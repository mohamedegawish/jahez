<?php

use App\Enums\FinancialPolicyKind;
use App\Enums\FinancialPolicyVersionStatus;
use App\Enums\Permission;
use App\Models\FinancialPolicy;
use App\Models\FinancialPolicyVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A pending version prepared by $preparer.
 */
function fppPending(User $preparer): FinancialPolicyVersion
{
    $version = approvedPolicyVersion(FinancialPolicyKind::Tax, ['prices_include_tax' => false, 'taxes' => [], 'fees' => []]);
    DB::table('financial_policy_versions')->where('id', $version->id)->update([
        'status' => FinancialPolicyVersionStatus::PendingApproval->value, 'created_by_user_id' => $preparer->id, 'submitted_by_user_id' => $preparer->id,
    ]);

    return $version->refresh();
}

test('record decisions for each actor', function (string $actor, string $ability, string $expectedDecision) {
    $preparer = financeAdmin(Permission::FinancialPoliciesManage, Permission::FinancialPoliciesApprove);
    $user = match ($actor) {
        'administrator' => User::factory()->imcAdmin()->create(),
        'manager' => financeAdmin(Permission::FinancialPoliciesManage),
        'approver' => financeAdmin(Permission::FinancialPoliciesApprove),
        'preparer' => $preparer,
        'factory' => User::factory()->factoryMember()->create(),
        'provider' => User::factory()->providerMember()->create(),
    };
    $version = fppPending($preparer);

    $response = match ($ability) {
        'viewAny', 'create' => Gate::forUser($user)->inspect($ability, FinancialPolicy::class),
        'draft' => Gate::forUser($user)->inspect($ability, $version->policy()->firstOrFail()),
        default => Gate::forUser($user)->inspect($ability, $version),
    };

    expect($response->allowed() ? 'allow' : 'deny '.($response->status() ?? 403))->toBe($expectedDecision);
})->with([
    ['administrator', 'viewAny', 'allow'],
    ['administrator', 'view', 'allow'],
    ['administrator', 'create', 'deny 403'],
    ['administrator', 'prepare', 'deny 403'],
    ['administrator', 'decide', 'deny 403'],
    ['administrator', 'end', 'deny 403'],
    ['manager', 'create', 'allow'],
    ['manager', 'draft', 'allow'],
    ['manager', 'prepare', 'allow'],
    ['manager', 'decide', 'deny 403'],
    ['approver', 'create', 'deny 403'],
    ['approver', 'prepare', 'deny 403'],
    ['approver', 'decide', 'allow'],
    ['approver', 'end', 'allow'],
    ['preparer', 'decide', 'deny 403'],
    ['factory', 'viewAny', 'deny 403'],
    ['factory', 'view', 'deny 404'],
    ['factory', 'decide', 'deny 404'],
    ['provider', 'view', 'deny 404'],
    ['provider', 'prepare', 'deny 404'],
]);
