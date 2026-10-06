<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Factory;
use App\Models\TransformationPlan;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Factory transformation plans (ADR-025). IMC writes them (transformation_plans.manage)
 * and reads them all (transformation_plans.view_any). A factory's own members read their
 * factory's plan once it has been published, never a draft, a version history or IMC's
 * notes, and change nothing. Providers and other factories are told the plan does not
 * exist.
 */
class TransformationPlanPolicy
{
    /**
     * List plans: IMC administrators (all) and factory members (their factory's).
     */
    public function viewAny(User $user): Response
    {
        return $user->hasPermission(Permission::TransformationPlansViewAny) || $user->factory_id !== null
            ? Response::allow()
            : Response::deny();
    }

    public function view(User $user, TransformationPlan $plan): Response
    {
        if ($user->hasPermission(Permission::TransformationPlansViewAny)) {
            return Response::allow();
        }

        return $this->isFactoryReader($user, $plan) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Create a plan for the factory: IMC administrators with the manage permission. The
     * factory's own members may see the factory but not write its plan.
     */
    public function create(User $user, Factory $factory): Response
    {
        if ($user->hasPermission(Permission::TransformationPlansManage)) {
            return Response::allow();
        }

        return $user->hasPermission(Permission::TransformationPlansViewAny) || $user->belongsToFactory($factory->id)
            ? Response::deny()
            : Response::denyAsNotFound();
    }

    /**
     * Draft, publish, suspend, resume, close, delete, and record item execution (owner
     * decision 2026-10-05: IMC only).
     */
    public function manage(User $user, TransformationPlan $plan): Response
    {
        if ($user->hasPermission(Permission::TransformationPlansManage)) {
            return Response::allow();
        }

        return $this->view($user, $plan)->allowed() ? Response::deny() : Response::denyAsNotFound();
    }

    /**
     * The version history, with drafts and IMC's notes: IMC only.
     */
    public function viewVersions(User $user, TransformationPlan $plan): Response
    {
        if ($user->hasPermission(Permission::TransformationPlansViewAny)) {
            return Response::allow();
        }

        return $this->isFactoryReader($user, $plan) ? Response::deny() : Response::denyAsNotFound();
    }

    private function isFactoryReader(User $user, TransformationPlan $plan): bool
    {
        return $user->belongsToFactory($plan->factory_id) && $plan->status->isVisibleToFactory();
    }
}
