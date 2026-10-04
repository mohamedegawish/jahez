<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Agreement;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Agreements (ADR-017). The factory and the provider that agreed are its parties. IMC
 * administrators may see that an agreement exists and its contract status, never its
 * terms (PROPOSED, OQ-39). Anyone else is told it does not exist.
 */
class AgreementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::AgreementsViewAny)
            || $user->factory_id !== null
            || $user->service_provider_id !== null;
    }

    public function view(User $user, Agreement $agreement): Response
    {
        return $this->canSee($user, $agreement) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Draft a contract for the agreement: either party (PROPOSED, OQ-17).
     */
    public function draftContract(User $user, Agreement $agreement): Response
    {
        if ($agreement->sideOf($user) !== null) {
            return Response::allow();
        }

        return $this->canSee($user, $agreement) ? Response::deny() : Response::denyAsNotFound();
    }

    /**
     * Approve or reject the agreement: IMC reviewers (ADR-020). The parties see it but
     * may not decide.
     */
    public function review(User $user, Agreement $agreement): Response
    {
        if ($user->hasPermission(Permission::AgreementsReview)) {
            return Response::allow();
        }

        return $this->canSee($user, $agreement) ? Response::deny() : Response::denyAsNotFound();
    }

    private function canSee(User $user, Agreement $agreement): bool
    {
        return $agreement->sideOf($user) !== null || $user->hasPermission(Permission::AgreementsViewAny);
    }
}
