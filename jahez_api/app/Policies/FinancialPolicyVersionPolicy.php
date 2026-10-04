<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\FinancialPolicyVersion;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Versions of a financial policy (ADR-023). Preparing (financial_policies.manage) and
 * deciding (financial_policies.approve) are separate permissions granted to individual
 * administrators, and even an administrator holding both may not approve or reject a
 * version they drafted, edited or submitted. The state checks (draft only, pending only)
 * are made by App\Billing\FinancialPolicyLifecycle and answer 409.
 */
class FinancialPolicyVersionPolicy
{
    public function view(User $user, FinancialPolicyVersion $version): Response
    {
        return $user->hasPermission(Permission::FinancialPoliciesView) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * Edit or submit a draft.
     */
    public function prepare(User $user, FinancialPolicyVersion $version): Response
    {
        return $this->requires($user, Permission::FinancialPoliciesManage);
    }

    /**
     * Approve or reject a submitted version: an approver who did not prepare it.
     */
    public function decide(User $user, FinancialPolicyVersion $version): Response
    {
        $allowed = $this->requires($user, Permission::FinancialPoliciesApprove);
        if (! $allowed->allowed()) {
            return $allowed;
        }

        return $version->wasPreparedBy($user)
            ? Response::deny('The administrator who drafted, edited or submitted a policy version cannot approve or reject it.')
            : Response::allow();
    }

    /**
     * Discard a draft or a rejected version (financial_policies.manage), or withdraw an
     * approved version before it starts (financial_policies.approve).
     */
    public function archive(User $user, FinancialPolicyVersion $version): Response
    {
        return $this->requires($user, $version->status->isResolvable() ? Permission::FinancialPoliciesApprove : Permission::FinancialPoliciesManage);
    }

    /**
     * End an approved version early.
     */
    public function end(User $user, FinancialPolicyVersion $version): Response
    {
        return $this->requires($user, Permission::FinancialPoliciesApprove);
    }

    /**
     * Preview a calculation with the version's values, whatever its status.
     */
    public function preview(User $user, FinancialPolicyVersion $version): Response
    {
        return $this->view($user, $version);
    }

    private function requires(User $user, Permission $permission): Response
    {
        if (! $user->hasPermission(Permission::FinancialPoliciesView)) {
            return Response::denyAsNotFound();
        }

        return $user->hasPermission($permission) ? Response::allow() : Response::deny();
    }
}
