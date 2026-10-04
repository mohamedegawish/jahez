<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Contract drafts (ADR-017; OQ-17 interim). The two parties of the agreement may read
 * and cancel them; IMC administrators may see that a draft exists and its status, never
 * its content (PROPOSED, OQ-39). Anyone else is told it does not exist.
 */
class ContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::AgreementsViewAny)
            || $user->factory_id !== null
            || $user->service_provider_id !== null;
    }

    public function view(User $user, Contract $contract): Response
    {
        return $this->canSee($user, $contract) ? Response::allow() : Response::denyAsNotFound();
    }

    public function cancel(User $user, Contract $contract): Response
    {
        if ($contract->agreement?->sideOf($user) !== null) {
            return Response::allow();
        }

        return $this->canSee($user, $contract) ? Response::deny() : Response::denyAsNotFound();
    }

    private function canSee(User $user, Contract $contract): bool
    {
        return $contract->agreement?->sideOf($user) !== null || $user->hasPermission(Permission::AgreementsViewAny);
    }
}
