<?php

use App\Billing\FinancialPolicyLifecycle;
use App\Enums\AuditEvent;
use App\Enums\FinancialPolicyKind;
use App\Enums\FinancialPolicyScope;
use App\Enums\FinancialPolicyVersionStatus;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\CatalogService;
use App\Models\FinancialPolicyVersion;
use App\Models\Sector;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Valid parameters for each kind, as an administrator would send them.
 *
 * @return array<string, mixed>
 */
function fpParameters(FinancialPolicyKind $kind, array $overrides = []): array
{
    return [...match ($kind) {
        FinancialPolicyKind::RevenueShare => ['method' => 'percentage', 'rate_percent' => '12.5', 'base' => 'subtotal_before_tax'],
        FinancialPolicyKind::Tax => ['prices_include_tax' => false, 'taxes' => [['code' => 'vat', 'name_ar' => 'ضريبة القيمة المضافة', 'rate_percent' => '14']], 'fees' => []],
        FinancialPolicyKind::Invoicing => [
            'issuer' => 'imc', 'payer' => 'factory', 'currency' => 'EGP', 'number_prefix' => 'IMC', 'number_padding' => 5,
            'invoice_types' => ['agreement_service'], 'manual_payments_allowed' => true, 'requires_revenue_share' => true,
        ],
        FinancialPolicyKind::PaymentTerms => ['due_rule' => 'days_after_issue', 'due_days' => 15, 'partial_payments_allowed' => false],
        FinancialPolicyKind::ContractTemplate => [
            'title_ar' => 'عقد تقديم خدمة تحول رقمي', 'parties' => ['factory', 'service_provider'], 'duration_months' => 12,
            'knowledge_transfer_min_trainees' => 3, 'clauses' => [['heading_ar' => 'نطاق العمل', 'body_ar' => 'يلتزم مقدم الخدمة بتنفيذ نطاق العمل المتفق عليه.']],
        ],
    }, ...$overrides];
}

/**
 * The body that creates a global policy of the kind with its first draft.
 *
 * @return array<string, mixed>
 */
function fpBody(FinancialPolicyKind $kind, array $overrides = []): array
{
    return [
        'kind' => $kind->value,
        'scope_type' => 'global',
        'name_ar' => $kind->labelAr(),
        'parameters' => fpParameters($kind),
        'effective_from' => '2026-10-05',
        'effective_to' => null,
        'change_reason' => 'قرار مجلس الإدارة رقم 1',
        ...$overrides,
    ];
}

/**
 * Drafts a policy as one administrator, submits it, and approves it as another.
 */
function fpApproved(FinancialPolicyKind $kind, array $overrides = [], ?User $maker = null, ?User $checker = null): FinancialPolicyVersion
{
    $maker ??= financeAdmin(Permission::FinancialPoliciesManage);
    $checker ??= financeAdmin(Permission::FinancialPoliciesApprove);

    Sanctum::actingAs($maker);
    $id = test()->postJson(route('api.v1.financial-policies.store'), fpBody($kind, $overrides))->assertCreated()->json('data.id');
    test()->postJson(route('api.v1.financial-policy-versions.submit', $id))->assertOk();
    Sanctum::actingAs($checker);
    test()->postJson(route('api.v1.financial-policy-versions.approve', $id), ['reason' => 'معتمد'])->assertOk();

    return FinancialPolicyVersion::query()->findOrFail($id);
}

/**
 * Drafts and submits the next version of a policy as $maker; returns its id.
 */
function fpNextVersion(FinancialPolicyVersion $of, array $body, User $maker): int
{
    Sanctum::actingAs($maker);
    $id = test()->postJson(route('api.v1.financial-policies.versions.store', $of->financial_policy_id), [
        'parameters' => $of->parameters, 'effective_to' => null, 'change_reason' => 'تعديل', ...$body,
    ])->assertCreated()->json('data.id');
    test()->postJson(route('api.v1.financial-policy-versions.submit', $id))->assertOk();

    return (int) $id;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00', 'UTC'));
});

describe('authorization', function () {
    it('lets IMC administrators read policies and refuses everyone else', function (Closure $makeUser, int $status) {
        Sanctum::actingAs($makeUser());

        $this->getJson(route('api.v1.financial-policies.index'))->assertStatus($status);
    })->with([
        'IMC administrator' => [fn () => User::factory()->imcAdmin()->create(), 200],
        'factory member' => [fn () => User::factory()->factoryMember()->create(), 403],
        'provider member' => [fn () => User::factory()->providerMember()->create(), 403],
    ]);

    it('answers 401 without a token', function () {
        $this->getJson(route('api.v1.financial-policies.index'))->assertUnauthorized();
    });

    it('needs the individually granted manage permission to create a policy', function (Closure $makeUser, int $status) {
        Sanctum::actingAs($makeUser());

        $this->postJson(route('api.v1.financial-policies.store'), fpBody(FinancialPolicyKind::RevenueShare))->assertStatus($status);
    })->with([
        'an ordinary IMC administrator' => [fn () => User::factory()->imcAdmin()->create(), 403],
        'an approver only' => [fn () => financeAdmin(Permission::FinancialPoliciesApprove), 403],
        'a factory member' => [fn () => User::factory()->factoryMember()->create(), 403],
        'a policy manager' => [fn () => financeAdmin(Permission::FinancialPoliciesManage), 201],
    ]);

    it('hides a policy version from members as not found', function () {
        $version = fpApproved(FinancialPolicyKind::RevenueShare);
        Sanctum::actingAs(User::factory()->providerMember()->create());

        $this->getJson(route('api.v1.financial-policy-versions.show', $version))->assertNotFound();
        $this->getJson(route('api.v1.financial-policies.show', $version->financial_policy_id))->assertNotFound();
        $this->postJson(route('api.v1.financial-policy-versions.approve', $version))->assertNotFound();
    });

    it('lists granted permissions on /me only for active IMC administrators', function () {
        $admin = financeAdmin(Permission::FinancialPoliciesApprove);
        Sanctum::actingAs($admin);
        expect($this->getJson(route('api.v1.me'))->json('data.permissions'))->toContain('financial_policies.approve', 'financial_policies.view')->not->toContain('financial_policies.manage');

        $member = User::factory()->factoryMember()->create();
        DB::table('user_permission_grants')->insert(['user_id' => $member->id, 'permission' => 'financial_policies.approve', 'reason' => 'x', 'created_at' => now()]);
        expect($member->refresh()->hasPermission(Permission::FinancialPoliciesApprove))->toBeFalse();

        $admin->deactivated_at = now();
        $admin->save();
        expect($admin->refresh()->hasPermission(Permission::FinancialPoliciesApprove))->toBeFalse();
    });

    it('never gives the individually granted permissions to the administrator role', function () {
        $admin = User::factory()->imcAdmin()->create();

        foreach (Permission::grantedIndividually() as $permission) {
            expect($admin->hasPermission($permission))->toBeFalse();
        }
        expect($admin->hasPermission(Permission::FinancialPoliciesView))->toBeTrue();
    });
});

describe('validation', function () {
    beforeEach(function () {
        $this->seed(ReferenceDataSeeder::class);
        Sanctum::actingAs(financeAdmin(Permission::FinancialPoliciesManage));
    });

    it('refuses invalid values with the field that is wrong', function (FinancialPolicyKind $kind, array $overrides, string $field) {
        $this->postJson(route('api.v1.financial-policies.store'), fpBody($kind, $overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
        $this->assertDatabaseCount('financial_policies', 0);
    })->with([
        'a rate above 100%' => [FinancialPolicyKind::RevenueShare, ['parameters' => fpParameters(FinancialPolicyKind::RevenueShare, ['rate_percent' => '100.01'])], 'parameters.rate_percent'],
        'a rate with three decimals' => [FinancialPolicyKind::RevenueShare, ['parameters' => fpParameters(FinancialPolicyKind::RevenueShare, ['rate_percent' => '12.125'])], 'parameters.rate_percent'],
        'a rate sent as a JSON number' => [FinancialPolicyKind::RevenueShare, ['parameters' => fpParameters(FinancialPolicyKind::RevenueShare, ['rate_percent' => 12.5])], 'parameters.rate_percent'],
        'a missing rate' => [FinancialPolicyKind::RevenueShare, ['parameters' => ['method' => 'percentage', 'base' => 'subtotal_before_tax']], 'parameters.rate_percent'],
        'an unsupported method' => [FinancialPolicyKind::RevenueShare, ['parameters' => fpParameters(FinancialPolicyKind::RevenueShare, ['method' => 'tiered'])], 'parameters.method'],
        'taxes above 100% together' => [FinancialPolicyKind::Tax, ['parameters' => fpParameters(FinancialPolicyKind::Tax, ['taxes' => [
            ['code' => 'a', 'name_ar' => 'أ', 'rate_percent' => '60'], ['code' => 'b', 'name_ar' => 'ب', 'rate_percent' => '41'],
        ]])], 'parameters.taxes'],
        'tax-inclusive pricing not stated' => [FinancialPolicyKind::Tax, ['parameters' => ['taxes' => [], 'fees' => []]], 'parameters.prices_include_tax'],
        'a fixed fee without an amount' => [FinancialPolicyKind::Tax, ['parameters' => fpParameters(FinancialPolicyKind::Tax, ['fees' => [
            ['code' => 'f', 'name_ar' => 'رسم', 'calculation' => 'fixed', 'taxable' => false],
        ]])], 'parameters.fees.0.amount'],
        'an unknown issuer' => [FinancialPolicyKind::Invoicing, ['parameters' => fpParameters(FinancialPolicyKind::Invoicing, ['issuer' => 'factory'])], 'parameters.issuer'],
        'a prefix with spaces' => [FinancialPolicyKind::Invoicing, ['parameters' => fpParameters(FinancialPolicyKind::Invoicing, ['number_prefix' => 'JZ 2026'])], 'parameters.number_prefix'],
        'a template without the provider' => [FinancialPolicyKind::ContractTemplate, ['parameters' => fpParameters(FinancialPolicyKind::ContractTemplate, ['parties' => ['factory', 'imc']])], 'parameters.parties'],
        'fewer trainees than DOC §6 requires' => [FinancialPolicyKind::ContractTemplate, ['parameters' => fpParameters(FinancialPolicyKind::ContractTemplate, ['knowledge_transfer_min_trainees' => 1])], 'parameters.knowledge_transfer_min_trainees'],
        'a start date in the past' => [FinancialPolicyKind::RevenueShare, ['effective_from' => '2026-10-04'], 'effective_from'],
        'an end before the start' => [FinancialPolicyKind::RevenueShare, ['effective_from' => '2026-10-10', 'effective_to' => '2026-10-09'], 'effective_to'],
        'no reason for the change' => [FinancialPolicyKind::RevenueShare, ['change_reason' => ''], 'change_reason'],
        'a scope this kind cannot have' => [FinancialPolicyKind::Invoicing, ['scope_type' => 'sector', 'scope_code' => 'food'], 'scope_type'],
        'an unknown sector' => [FinancialPolicyKind::RevenueShare, ['scope_type' => 'sector', 'scope_code' => 'space'], 'scope_code'],
        'a sector named by id' => [FinancialPolicyKind::RevenueShare, ['scope_type' => 'sector', 'scope_id' => 1], 'scope_code'],
        'a scope that does not exist' => [FinancialPolicyKind::RevenueShare, ['scope_type' => 'catalog_service', 'scope_id' => 999999], 'scope_id'],
        'a global policy with a scope id' => [FinancialPolicyKind::RevenueShare, ['scope_id' => 1], 'scope_id'],
    ]);

    it('stores percentages and amounts normalised to two decimals', function () {
        $response = $this->postJson(route('api.v1.financial-policies.store'), fpBody(FinancialPolicyKind::RevenueShare));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.parameters.rate_percent', '12.50')
            ->assertJsonPath('data.policy.scope.type', 'global');
    });

    it('keeps one policy per kind and scope', function () {
        $this->postJson(route('api.v1.financial-policies.store'), fpBody(FinancialPolicyKind::RevenueShare))->assertCreated();

        $this->postJson(route('api.v1.financial-policies.store'), fpBody(FinancialPolicyKind::RevenueShare))
            ->assertConflict()
            ->assertJsonPath('message', 'A policy of this kind already exists for this scope; add a new version to it instead.');
    });
});

describe('approval', function () {
    it('needs a second administrator: whoever prepared a version cannot approve it', function () {
        $both = financeAdmin(Permission::FinancialPoliciesManage, Permission::FinancialPoliciesApprove);
        Sanctum::actingAs($both);
        $id = $this->postJson(route('api.v1.financial-policies.store'), fpBody(FinancialPolicyKind::RevenueShare))->json('data.id');
        $this->postJson(route('api.v1.financial-policy-versions.submit', $id))->assertOk()->assertJsonPath('data.status', 'pending_approval');

        $this->postJson(route('api.v1.financial-policy-versions.approve', $id))
            ->assertForbidden()
            ->assertJsonPath('message', 'The administrator who drafted, edited or submitted a policy version cannot approve or reject it.');
        $this->getJson(route('api.v1.financial-policy-versions.show', $id))->assertJsonPath('data.actions.approve', false);

        $checker = financeAdmin(Permission::FinancialPoliciesApprove);
        Sanctum::actingAs($checker);
        $this->getJson(route('api.v1.financial-policy-versions.show', $id))->assertJsonPath('data.actions.approve', true);
        $this->postJson(route('api.v1.financial-policy-versions.approve', $id), ['reason' => 'مراجَع'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.effective_status', 'active')
            ->assertJsonPath('data.decided_by.id', $checker->id)
            ->assertJsonPath('data.decision_note', 'مراجَع');
    });

    it('refuses approval to a manager who holds no approve permission', function () {
        $maker = financeAdmin(Permission::FinancialPoliciesManage);
        Sanctum::actingAs($maker);
        $id = $this->postJson(route('api.v1.financial-policies.store'), fpBody(FinancialPolicyKind::RevenueShare))->json('data.id');
        $this->postJson(route('api.v1.financial-policy-versions.submit', $id))->assertOk();
        Sanctum::actingAs(financeAdmin(Permission::FinancialPoliciesManage));

        $this->postJson(route('api.v1.financial-policy-versions.approve', $id))->assertForbidden();
        $this->postJson(route('api.v1.financial-policy-versions.reject', $id), ['reason' => 'لا'])->assertForbidden();
    });

    it('refuses the same separation inside the business service, not only over HTTP', function () {
        $both = financeAdmin(Permission::FinancialPoliciesManage, Permission::FinancialPoliciesApprove);
        Sanctum::actingAs($both);
        $id = $this->postJson(route('api.v1.financial-policies.store'), fpBody(FinancialPolicyKind::RevenueShare))->json('data.id');
        $this->postJson(route('api.v1.financial-policy-versions.submit', $id))->assertOk();

        expect(fn () => FinancialPolicyLifecycle::approve($both, FinancialPolicyVersion::query()->findOrFail($id), null))
            ->toThrow(AuthorizationException::class);
        expect(fn () => FinancialPolicyLifecycle::approve(User::factory()->imcAdmin()->create(), FinancialPolicyVersion::query()->findOrFail($id), null))
            ->toThrow(AuthorizationException::class);
    });

    it('allows only the transitions of the lifecycle', function () {
        $maker = financeAdmin(Permission::FinancialPoliciesManage);
        $checker = financeAdmin(Permission::FinancialPoliciesApprove);
        Sanctum::actingAs($maker);
        $id = $this->postJson(route('api.v1.financial-policies.store'), fpBody(FinancialPolicyKind::RevenueShare))->json('data.id');

        Sanctum::actingAs($checker);
        $this->postJson(route('api.v1.financial-policy-versions.approve', $id))->assertConflict()->assertJsonPath('message', 'This version is draft and cannot be approved.');

        Sanctum::actingAs($maker);
        $this->postJson(route('api.v1.financial-policy-versions.submit', $id))->assertOk();
        $this->patchJson(route('api.v1.financial-policy-versions.update', $id), ['change_reason' => 'late edit'])->assertConflict();
        $this->postJson(route('api.v1.financial-policy-versions.submit', $id))->assertConflict();

        Sanctum::actingAs($checker);
        $this->postJson(route('api.v1.financial-policy-versions.reject', $id))->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postJson(route('api.v1.financial-policy-versions.approve', $id))->assertOk();
        $this->postJson(route('api.v1.financial-policy-versions.reject', $id), ['reason' => 'متأخر'])->assertConflict();
        $this->postJson(route('api.v1.financial-policy-versions.archive', $id), ['reason' => 'سارية'])
            ->assertConflict()
            ->assertJsonPath('message', 'This version is already in effect; end it instead of archiving it.');
    });

    it('keeps one open version per policy', function () {
        $version = fpApproved(FinancialPolicyKind::RevenueShare);
        $maker = financeAdmin(Permission::FinancialPoliciesManage);
        fpNextVersion($version, ['effective_from' => '2026-11-01'], $maker);

        $this->postJson(route('api.v1.financial-policies.versions.store', $version->financial_policy_id), [
            'parameters' => $version->parameters, 'effective_from' => '2026-12-01', 'effective_to' => null, 'change_reason' => 'ثالث',
        ])->assertConflict()->assertJsonPath('message', 'Version 2 of this policy is still pending_approval; finish or archive it before drafting another.');
    });

    it('never approves retroactively: a pending version whose start passed must be redrafted', function () {
        $maker = financeAdmin(Permission::FinancialPoliciesManage);
        Sanctum::actingAs($maker);
        $id = $this->postJson(route('api.v1.financial-policies.store'), fpBody(FinancialPolicyKind::RevenueShare, ['effective_from' => '2026-10-06']))->json('data.id');
        $this->postJson(route('api.v1.financial-policy-versions.submit', $id))->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 08:00:00', 'UTC'));
        Sanctum::actingAs(financeAdmin(Permission::FinancialPoliciesApprove));

        $this->postJson(route('api.v1.financial-policy-versions.approve', $id))
            ->assertConflict()
            ->assertJsonPath('message', 'The start date of this version has passed, and nothing is approved retroactively; reject it and draft a new version.');
    });

    it('records who did what, why, and the values before and after, in the history', function () {
        $maker = financeAdmin(Permission::FinancialPoliciesManage);
        Sanctum::actingAs($maker);
        $id = $this->postJson(route('api.v1.financial-policies.store'), fpBody(FinancialPolicyKind::RevenueShare))->json('data.id');
        $this->patchJson(route('api.v1.financial-policy-versions.update', $id), [
            'parameters' => fpParameters(FinancialPolicyKind::RevenueShare, ['rate_percent' => '15']),
            'change_reason' => 'تصحيح النسبة',
        ])->assertOk();
        $this->postJson(route('api.v1.financial-policy-versions.submit', $id))->assertOk();
        Sanctum::actingAs(financeAdmin(Permission::FinancialPoliciesApprove));
        $this->postJson(route('api.v1.financial-policy-versions.approve', $id), ['reason' => 'معتمد'])->assertOk();

        $history = $this->getJson(route('api.v1.financial-policy-versions.history', $id))->assertOk()->json('data');

        expect(array_column($history, 'event'))->toBe([
            'financial_policy.created', 'financial_policy.version_drafted', 'financial_policy.version_updated',
            'financial_policy.version_submitted', 'financial_policy.version_approved',
        ])->and($history[2]['actor']['id'])->toBe($maker->id)
            ->and($history[2]['metadata']['changes']['parameters.rate_percent'])->toEqual(['from' => '12.50', 'to' => '15.00'])
            ->and($history[2]['metadata']['changes']['change_reason']['to'])->toBe('تصحيح النسبة')
            ->and($history[4]['metadata'])->toMatchArray(['version' => 1, 'effective_from' => '2026-10-05', 'note' => 'معتمد'])
            ->and($history[0])->not->toHaveKey('ip_address');
    });

    it('records nothing for an edit that changes nothing', function () {
        Sanctum::actingAs(financeAdmin(Permission::FinancialPoliciesManage));
        $id = $this->postJson(route('api.v1.financial-policies.store'), fpBody(FinancialPolicyKind::RevenueShare))->json('data.id');

        $this->patchJson(route('api.v1.financial-policy-versions.update', $id), ['change_reason' => 'قرار مجلس الإدارة رقم 1'])->assertOk();

        expect(AuditLog::query()->where('event', AuditEvent::FinancialPolicyVersionUpdated->value)->count())->toBe(0);
    });

    it('never edits an approved version, even through the model', function () {
        $version = fpApproved(FinancialPolicyKind::RevenueShare);

        $version->parameters = fpParameters(FinancialPolicyKind::RevenueShare, ['rate_percent' => '99.00']);
        expect(fn () => $version->save())->toThrow(LogicException::class, 'An approved policy version is never edited: parameters.');

        $fresh = $version->fresh();
        $fresh->effective_to = CarbonImmutable::parse('2027-01-01');
        $fresh->save();
        $fresh->effective_to = CarbonImmutable::parse('2027-06-01');
        expect(fn () => $fresh->save())->toThrow(LogicException::class, 'An approved policy version can only end earlier, never later.')
            ->and(fn () => $fresh->delete())->toThrow(LogicException::class);
    });
});

describe('effective dates and supersession', function () {
    it('supersedes the version in effect: it ends the day before its successor starts', function () {
        $first = fpApproved(FinancialPolicyKind::RevenueShare);
        $second = fpNextVersion($first, ['effective_from' => '2026-11-01', 'parameters' => fpParameters(FinancialPolicyKind::RevenueShare, ['rate_percent' => '10'])], financeAdmin(Permission::FinancialPoliciesManage));
        Sanctum::actingAs(financeAdmin(Permission::FinancialPoliciesApprove));

        $this->postJson(route('api.v1.financial-policy-versions.approve', $second))->assertOk()->assertJsonPath('data.effective_status', 'scheduled');

        expect($first->refresh()->status)->toBe(FinancialPolicyVersionStatus::Superseded)
            ->and($first->effective_to?->toDateString())->toBe('2026-10-31')
            ->and($first->superseded_by_version_id)->toBe($second)
            ->and(AuditLog::query()->where('event', AuditEvent::FinancialPolicyVersionSuperseded->value)->sole()->metadata)->toMatchArray(['effective_to' => ['from' => null, 'to' => '2026-10-31']]);

        $rate = fn (string $day) => $this->getJson(route('api.v1.financial-policies.resolve', ['kind' => 'revenue_share', 'date' => $day]))->json('data.version.parameters.rate_percent');
        expect($rate('2026-10-31'))->toBe('12.50')->and($rate('2026-11-01'))->toBe('10.00');
    });

    it('refuses overlapping approved periods for the same scope', function () {
        $first = fpApproved(FinancialPolicyKind::RevenueShare);
        $maker = financeAdmin(Permission::FinancialPoliciesManage);
        $checker = financeAdmin(Permission::FinancialPoliciesApprove);
        $second = fpNextVersion($first, ['effective_from' => '2026-12-01'], $maker);
        Sanctum::actingAs($checker);
        $this->postJson(route('api.v1.financial-policy-versions.approve', $second))->assertOk();

        Sanctum::actingAs($maker);
        $third = $this->postJson(route('api.v1.financial-policies.versions.store', $first->financial_policy_id), [
            'parameters' => $first->parameters, 'effective_from' => '2026-11-01', 'effective_to' => '2026-12-15', 'change_reason' => 'تداخل',
        ])->assertCreated()->json('data.id');

        // Checked when submitted, and again under the policy lock when approved.
        $this->postJson(route('api.v1.financial-policy-versions.submit', $third))
            ->assertConflict()
            ->assertJsonPath('message', "This version's period overlaps approved version 2 (from 2026-12-01); change its dates, or archive or end version 2 first.");
        expect(FinancialPolicyVersion::query()->findOrFail($third)->status)->toBe(FinancialPolicyVersionStatus::Draft);

        DB::table('financial_policy_versions')->where('id', $third)->update(['status' => 'pending_approval', 'submitted_by_user_id' => $maker->id]);
        Sanctum::actingAs($checker);
        $this->postJson(route('api.v1.financial-policy-versions.approve', $third))->assertConflict();
        expect(FinancialPolicyVersion::query()->findOrFail($third)->status)->toBe(FinancialPolicyVersionStatus::PendingApproval);
    });

    it('applies a scheduled version only from its start, and an ended one only until its last day', function () {
        $version = fpApproved(FinancialPolicyKind::RevenueShare, ['effective_from' => '2026-10-10']);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $resolve = fn (string $day) => $this->getJson(route('api.v1.financial-policies.resolve', ['kind' => 'revenue_share', 'date' => $day]))->json('data.version.id');

        expect($resolve('2026-10-09'))->toBeNull()->and($resolve('2026-10-10'))->toBe($version->id);

        $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
        Sanctum::actingAs(financeAdmin(Permission::FinancialPoliciesApprove));
        $this->postJson(route('api.v1.financial-policy-versions.end', $version), ['effective_to' => '2026-10-11', 'reason' => 'إيقاف'])
            ->assertUnprocessable()->assertJsonValidationErrors(['effective_to']);
        $this->postJson(route('api.v1.financial-policy-versions.end', $version), ['effective_to' => '2026-10-20', 'reason' => 'إيقاف'])
            ->assertOk()->assertJsonPath('data.status', 'ended');

        expect($resolve('2026-10-20'))->toBe($version->id)->and($resolve('2026-10-21'))->toBeNull();
    });

    it('withdraws a scheduled version, which then never applies', function () {
        $version = fpApproved(FinancialPolicyKind::RevenueShare, ['effective_from' => '2026-10-10']);
        Sanctum::actingAs(financeAdmin(Permission::FinancialPoliciesManage));
        $this->postJson(route('api.v1.financial-policy-versions.archive', $version), ['reason' => 'سحب'])->assertForbidden();

        Sanctum::actingAs(financeAdmin(Permission::FinancialPoliciesApprove));
        $this->postJson(route('api.v1.financial-policy-versions.archive', $version), ['reason' => 'سحب'])->assertOk()->assertJsonPath('data.status', 'archived');

        $this->getJson(route('api.v1.financial-policies.resolve', ['kind' => 'revenue_share', 'date' => '2026-10-15']))->assertJsonPath('data.version', null);
    });

    it('cannot withdraw a scheduled version that superseded another', function () {
        $first = fpApproved(FinancialPolicyKind::RevenueShare);
        $second = fpNextVersion($first, ['effective_from' => '2026-11-01'], financeAdmin(Permission::FinancialPoliciesManage));
        Sanctum::actingAs(financeAdmin(Permission::FinancialPoliciesApprove));
        $this->postJson(route('api.v1.financial-policy-versions.approve', $second))->assertOk();

        $this->postJson(route('api.v1.financial-policy-versions.archive', $second), ['reason' => 'سحب'])
            ->assertConflict()
            ->assertJsonPath('message', 'This version replaced version 1, whose end date cannot move later; approve a new version instead.');
    });
});

describe('scope resolution', function () {
    beforeEach(fn () => $this->seed(ReferenceDataSeeder::class));

    it('applies the most specific scope: provider, then service, then sector, then global', function () {
        ['agreement' => $agreement] = agreedMarketplace();
        $sectorId = (int) DB::table('factory_sector')->where('factory_id', $agreement->factory_id)->value('sector_id');
        $sectorCode = Sector::query()->whereKey($sectorId)->value('code');
        approvedPolicyVersion(FinancialPolicyKind::RevenueShare, fpParameters(FinancialPolicyKind::RevenueShare, ['rate_percent' => '10']));
        approvedPolicyVersion(FinancialPolicyKind::RevenueShare, fpParameters(FinancialPolicyKind::RevenueShare, ['rate_percent' => '11']), FinancialPolicyScope::Sector, $sectorId);
        approvedPolicyVersion(FinancialPolicyKind::RevenueShare, fpParameters(FinancialPolicyKind::RevenueShare, ['rate_percent' => '12']), FinancialPolicyScope::CatalogService, $agreement->catalog_service_id);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $rate = fn (array $context) => $this->getJson(route('api.v1.financial-policies.resolve', ['kind' => 'revenue_share', ...$context]))->assertOk()->json('data.version.parameters.rate_percent');

        expect($rate([]))->toBe('10.00')
            ->and($rate(['sectors' => [$sectorCode]]))->toBe('11.00')
            ->and($rate(['sectors' => [$sectorCode], 'catalog_service' => $agreement->catalog_service_id]))->toBe('12.00');

        approvedPolicyVersion(FinancialPolicyKind::RevenueShare, fpParameters(FinancialPolicyKind::RevenueShare, ['rate_percent' => '13']), FinancialPolicyScope::ServiceProvider, $agreement->service_provider_id);
        expect($rate(['catalog_service' => $agreement->catalog_service_id, 'service_provider' => $agreement->service_provider_id]))->toBe('13.00')
            ->and($rate(['catalog_service' => CatalogService::query()->whereKeyNot($agreement->catalog_service_id)->value('id')]))->toBe('10.00');
    });

    it('refuses to choose between two sector policies that both apply', function () {
        [$first, $second] = Sector::query()->orderBy('id')->limit(2)->get()->all();
        approvedPolicyVersion(FinancialPolicyKind::RevenueShare, fpParameters(FinancialPolicyKind::RevenueShare, ['rate_percent' => '10']), FinancialPolicyScope::Sector, $first->id);
        approvedPolicyVersion(FinancialPolicyKind::RevenueShare, fpParameters(FinancialPolicyKind::RevenueShare, ['rate_percent' => '20']), FinancialPolicyScope::Sector, $second->id);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->getJson(route('api.v1.financial-policies.resolve', ['kind' => 'revenue_share', 'sectors' => [$first->code, $second->code]]))
            ->assertConflict()
            ->assertJsonPath('code', 'policy_not_configured')
            ->assertJsonPath('decision_needed', 'OQ-47');
    });

    it('explains in Arabic that nothing applies, and assumes no default rate', function () {
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->getJson(route('api.v1.financial-policies.resolve', ['kind' => 'revenue_share']))
            ->assertOk()
            ->assertJsonPath('data.version', null)
            ->assertJsonPath('data.reason_ar', fn (string $reason): bool => str_contains($reason, '«حصة الوزارة من الإيرادات»'));
    });

    it('previews an invoice with the server\'s calculation and the versions used', function () {
        approvedPolicyVersion(FinancialPolicyKind::Tax, fpParameters(FinancialPolicyKind::Tax));
        approvedPolicyVersion(FinancialPolicyKind::RevenueShare, fpParameters(FinancialPolicyKind::RevenueShare, ['rate_percent' => '20']));
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->postJson(route('api.v1.financial-policies.preview'), ['amount' => '1000.05', 'rate_percent' => '1', 'total' => '1'])
            ->assertOk()
            ->assertJsonPath('data.calculation.tax_total', '140.01')
            ->assertJsonPath('data.calculation.total', '1140.06')
            ->assertJsonPath('data.calculation.revenue_share.amount', '200.01')
            ->assertJsonPath('data.policy_versions.invoicing', null)
            ->assertJsonPath('data.missing_ar.0', 'لا توجد سياسة فوترة معتمدة سارية؛ لا يمكن إعداد الفاتورة.');
    });

    it('previews a draft version\'s effect before it is approved', function () {
        Sanctum::actingAs(financeAdmin(Permission::FinancialPoliciesManage));
        $id = $this->postJson(route('api.v1.financial-policies.store'), fpBody(FinancialPolicyKind::Tax, ['parameters' => fpParameters(FinancialPolicyKind::Tax, ['prices_include_tax' => true])]))->json('data.id');

        $this->postJson(route('api.v1.financial-policy-versions.preview', $id), ['amount' => '1140.06'])
            ->assertOk()
            ->assertJsonPath('data.calculation.net_service_amount', '1000.05')
            ->assertJsonPath('data.calculation.total', '1140.06');
    });
});

describe('concurrency', function () {
    it('locks the policy row for update before approving, then its versions', function () {
        $maker = financeAdmin(Permission::FinancialPoliciesManage);
        Sanctum::actingAs($maker);
        $id = $this->postJson(route('api.v1.financial-policies.store'), fpBody(FinancialPolicyKind::RevenueShare))->json('data.id');
        $this->postJson(route('api.v1.financial-policy-versions.submit', $id))->assertOk();
        Sanctum::actingAs(financeAdmin(Permission::FinancialPoliciesApprove));

        $reads = lockingReads(fn () => $this->postJson(route('api.v1.financial-policy-versions.approve', $id))->assertOk());

        expect($reads)->toBe(['financial_policies:update', 'financial_policy_versions:update', 'financial_policy_versions:update']);
    });

    it('enforces one policy per kind and scope in the database too', function () {
        approvedPolicyVersion(FinancialPolicyKind::Tax, fpParameters(FinancialPolicyKind::Tax));

        expect(fn () => DB::table('financial_policies')->insert([
            'kind' => 'tax', 'scope_type' => 'global', 'scope_key' => 'global', 'name_ar' => 'x', 'created_by_user_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});
