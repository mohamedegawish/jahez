<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Which catalog services each readiness level makes available (ADR-025). IMC
 * administrators with readiness_services.manage change them; those who build plans
 * (transformation_plans.view_any) read them. Factories and providers never see this
 * administration: a factory reads its own available services through its eligibility.
 */
class ReadinessLevelServicePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission(Permission::ReadinessServicesManage) || $user->hasPermission(Permission::TransformationPlansViewAny)
            ? Response::allow()
            : Response::deny();
    }

    public function manage(User $user): Response
    {
        return $user->hasPermission(Permission::ReadinessServicesManage) ? Response::allow() : Response::deny();
    }
}
