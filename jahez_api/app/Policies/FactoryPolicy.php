<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Factory;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * IMC administrators manage every factory; a factory member may see only their own.
 * A factory the user may not see is reported as not found so its existence is not disclosed.
 */
class FactoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::FactoriesViewAny);
    }

    public function view(User $user, Factory $factory): Response
    {
        return $user->hasPermission(Permission::FactoriesViewAny) || $user->industrialFactory()->is($factory)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::FactoriesCreate);
    }

    /**
     * IMC administrators update any factory; a factory's own members update its name and
     * sectors (owner decision 2026-10-03). Nothing else on a factory is editable.
     */
    public function update(User $user, Factory $factory): Response
    {
        return $user->hasPermission(Permission::FactoriesUpdate) || $user->industrialFactory()->is($factory)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Approve, reject, ask for corrections or suspend a factory: IMC only (ADR-021).
     */
    public function approve(User $user, Factory $factory): Response
    {
        if ($user->hasPermission(Permission::FactoriesApprove)) {
            return Response::allow();
        }

        return $user->industrialFactory()->is($factory) ? Response::deny() : Response::denyAsNotFound();
    }

    /**
     * Ask IMC to review the factory again after a rejection or a request for
     * corrections: the factory's own members (ADR-021).
     */
    public function requestReview(User $user, Factory $factory): Response
    {
        if ($user->industrialFactory()->is($factory)) {
            return Response::allow();
        }

        return $user->hasPermission(Permission::FactoriesViewAny) ? Response::deny() : Response::denyAsNotFound();
    }

    /**
     * Ask IMC to change recorded legal information (ADR-020): the factory's own members.
     * IMC administrators edit it directly instead.
     */
    public function requestLegalChange(User $user, Factory $factory): Response
    {
        if ($user->industrialFactory()->is($factory)) {
            return Response::allow();
        }

        return $user->hasPermission(Permission::FactoriesViewAny) ? Response::deny() : Response::denyAsNotFound();
    }

    /**
     * Approve or reject a factory's legal change request: IMC administrators who update
     * factories. Members see their requests but may not decide them.
     */
    public function reviewLegalChange(User $user, Factory $factory): Response
    {
        if ($user->hasPermission(Permission::FactoriesUpdate)) {
            return Response::allow();
        }

        return $user->industrialFactory()->is($factory) ? Response::deny() : Response::denyAsNotFound();
    }

    /**
     * The queue of factory change requests waiting for review.
     */
    public function viewChangeRequestQueue(User $user): bool
    {
        return $user->hasPermission(Permission::FactoriesUpdate);
    }
}
