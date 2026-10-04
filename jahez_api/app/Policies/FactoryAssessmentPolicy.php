<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Factory;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Legacy manual IMC classifications of factories (ADR-016, superseded by ADR-018). They
 * are read-only history: IMC administrators and the factory's own members read them,
 * nobody records new ones. Anyone else is told the factory does not exist.
 */
class FactoryAssessmentPolicy
{
    public function viewAny(User $user, Factory $factory): Response
    {
        return $user->hasPermission(Permission::AssessmentsViewAny) || $user->industrialFactory()->is($factory)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
