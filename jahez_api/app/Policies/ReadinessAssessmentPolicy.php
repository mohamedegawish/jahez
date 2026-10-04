<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Factory;
use App\Models\ReadinessAssessment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Digital readiness assessments (ADR-018). A factory's own members submit them (a
 * self-assessment, owner decision 2026-10-03) and read their factory's results. IMC
 * administrators read every factory's results but submit none. Anyone else is told the
 * factory or the assessment does not exist.
 */
class ReadinessAssessmentPolicy
{
    public function viewAny(User $user, Factory $factory): Response
    {
        return $this->canRead($user, $factory->id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function view(User $user, ReadinessAssessment $assessment): Response
    {
        return $this->canRead($user, $assessment->factory_id)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user, Factory $factory): Response
    {
        if ($user->belongsToFactory($factory->id)) {
            return Response::allow();
        }

        return $user->hasPermission(Permission::AssessmentsViewAny)
            ? Response::deny('Only the factory\'s own members complete its readiness assessment.')
            : Response::denyAsNotFound();
    }

    private function canRead(User $user, int $factoryId): bool
    {
        return $user->hasPermission(Permission::AssessmentsViewAny) || $user->belongsToFactory($factoryId);
    }
}
