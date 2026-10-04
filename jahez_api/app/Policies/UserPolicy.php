<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * IMC administrators manage every account. Other users may see themselves and the
 * members of their own organization; anyone else is reported as not found.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::UsersViewAny);
    }

    public function view(User $user, User $model): Response
    {
        return $this->canSee($user, $model) ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::UsersCreate);
    }

    public function update(User $user, User $model): Response
    {
        if ($user->hasPermission(Permission::UsersUpdate)) {
            return Response::allow();
        }

        return $this->canSee($user, $model) ? Response::deny() : Response::denyAsNotFound();
    }

    private function canSee(User $user, User $model): bool
    {
        return $user->hasPermission(Permission::UsersViewAny)
            || $user->is($model)
            || $user->sharesOrganizationWith($model);
    }
}
