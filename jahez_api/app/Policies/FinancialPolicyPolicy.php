<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\FinancialPolicy;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Financial and contract policies (ADR-023). IMC administrators read them
 * (financial_policies.view); only administrators granted financial_policies.manage
 * create them. Factory and provider members are told they do not exist.
 */
class FinancialPolicyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::FinancialPoliciesView);
    }

    public function view(User $user, FinancialPolicy $policy): Response
    {
        return $user->hasPermission(Permission::FinancialPoliciesView) ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::FinancialPoliciesManage);
    }

    /**
     * Add a new draft version to an existing policy.
     */
    public function draft(User $user, FinancialPolicy $policy): Response
    {
        if (! $user->hasPermission(Permission::FinancialPoliciesView)) {
            return Response::denyAsNotFound();
        }

        return $user->hasPermission(Permission::FinancialPoliciesManage) ? Response::allow() : Response::deny();
    }
}
