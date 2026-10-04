<?php

use App\Enums\AgreementReviewStatus;
use App\Enums\AuditEvent;
use App\Enums\FinancialPolicyKind;
use App\Enums\InvoiceStatus;
use App\Enums\Permission;
use App\Enums\PolicyBasis;
use App\Models\Agreement;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\FinancialPolicyVersion;
use App\Models\Invoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * An invoice issued by the provider under the default test policies (configureBilling).
 *
 * @return array{factoryMember: User, providerMembers: list<User>, agreement: Agreement, invoice: Invoice}
 */
function fbIssuedInvoice(array $policies = []): array
{
    configureBilling($policies);
    $marketplace = draftInvoice();
    test()->postJson(route('api.v1.invoices.issue', $marketplace['invoice']))->assertOk();

    return [...$marketplace, 'invoice' => $marketplace['invoice']->refresh()];
}

function fbRecord(Invoice $invoice, array $body, string $key = 'manual-key-0001'): TestResponse
{
    return test()->postJson(route('api.v1.invoices.manual-payments.store', $invoice), [
        'amount' => '285000.00', 'received_on' => '2026-10-05', 'reference' => 'BANK-TRX-1', ...$body,
    ], ['Idempotency-Key' => $key]);
}

function fbContract(array $overrides = []): array
{
    return ['knowledge_transfer' => ['trainees' => 2, 'training_plan' => 'خطة تدريب مهندسَين من المركز'], ...$overrides];
}

function fbTemplate(array $overrides = []): array
{
    return [
        'title_ar' => 'عقد تقديم خدمة', 'parties' => ['factory', 'service_provider', 'imc'], 'duration_months' => 6,
        'knowledge_transfer_min_trainees' => 3, 'clauses' => [['heading_ar' => 'البند الأول', 'body_ar' => 'نص البند الأول']], ...$overrides,
    ];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00', 'UTC'));
});

describe('snapshots on issued invoices', function () {
    it('stores the policy versions and the whole calculation, with the due date from the payment terms', function () {
        ['invoice' => $invoice] = fbIssuedInvoice(['revenue_share' => ['rate_percent' => '20']]);

        $this->getJson(route('api.v1.invoices.show', $invoice))
            ->assertOk()
            ->assertJsonPath('data.policy_basis', 'policy')
            ->assertJsonPath('data.payer', 'factory')
            ->assertJsonPath('data.due_date', '2026-11-04')
            ->assertJsonPath('data.is_overdue', false)
            ->assertJsonPath('data.total', '285000.00')
            ->assertJsonPath('data.outstanding', '285000.00')
            ->assertJsonPath('data.calculation.taxes.0.amount', '35000.00')
            ->assertJsonPath('data.calculation.revenue_share.amount', '50000.00')
            ->assertJsonPath('data.policy_versions.tax.id', $invoice->tax_policy_version_id)
            ->assertJsonPath('data.policy_versions.revenue_share.id', $invoice->revenue_share_policy_version_id);
    });

    it('keeps an issued invoice exactly as it was after the policies change', function () {
        ['invoice' => $old, 'providerMembers' => $providerMembers] = fbIssuedInvoice();
        $before = $this->getJson(route('api.v1.invoices.show', $old))->json('data');
        $oldTaxVersion = $old->tax_policy_version_id;

        $newTax = approvedPolicyVersion(FinancialPolicyKind::Tax, ['prices_include_tax' => false, 'taxes' => [['code' => 'vat', 'name_ar' => 'ض', 'rate_percent' => '10']], 'fees' => []], from: '2026-10-06');
        DB::table('financial_policy_versions')->where('id', $oldTaxVersion)->update(['effective_to' => '2026-10-05', 'status' => 'superseded']);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00:00', 'UTC'));
        Sanctum::actingAs($providerMembers[0]);

        $after = $this->getJson(route('api.v1.invoices.show', $old))->json('data');
        expect(array_diff_key($after, ['is_overdue' => true]))->toEqual(array_diff_key($before, ['is_overdue' => true]))
            ->and($old->refresh()->tax_policy_version_id)->toBe($oldTaxVersion);

        ['invoice' => $new] = draftInvoice();
        $this->postJson(route('api.v1.invoices.issue', $new))->assertOk()->assertJsonPath('data.tax.amount', '25000.00');
        expect($new->refresh()->tax_policy_version_id)->toBe($newTax->id);
    });

    it('reports an unpaid invoice as overdue the day after its due date, without storing it', function () {
        ['invoice' => $invoice] = fbIssuedInvoice(['payment_terms' => ['due_days' => 3]]);
        expect($invoice->due_date?->toDateString())->toBe('2026-10-08');

        $this->travelTo(CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'));
        $this->getJson(route('api.v1.invoices.show', $invoice))->assertJsonPath('data.is_overdue', false);

        $this->travelTo(CarbonImmutable::parse('2026-10-08 22:30:00', 'UTC'));
        $this->getJson(route('api.v1.invoices.show', $invoice))->assertJsonPath('data.is_overdue', true)->assertJsonPath('data.status', 'issued');
    });

    it('numbers invoices in the format of the invoicing policy', function () {
        ['invoice' => $invoice] = fbIssuedInvoice(['invoicing' => ['number_prefix' => 'IMC/2026', 'number_padding' => 4]]);

        expect($invoice->number)->toBe('IMC/2026-0001');
    });

    it('refuses to issue a draft whose issuer the current invoicing policy no longer names', function () {
        configureBilling();
        ['invoice' => $invoice] = draftInvoice();
        configureBilling(['invoicing' => ['issuer' => 'imc']]);

        $this->postJson(route('api.v1.invoices.issue', $invoice))
            ->assertConflict()
            ->assertJsonPath('message', 'The invoicing policy in effect names a different issuer than this draft; cancel it and draft the invoice again.');
        Sanctum::actingAs(financeAdmin());
        $this->postJson(route('api.v1.invoices.issue', $invoice))->assertForbidden();
        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft);
    });
});

describe('manual payment entries', function () {
    it('records money received, with evidence, and settles the invoice only up to what is owed', function () {
        ['invoice' => $invoice] = fbIssuedInvoice();
        $recorder = financeAdmin(Permission::PaymentsRecord);
        Sanctum::actingAs($recorder);

        fbRecord($invoice, ['amount' => '285000.01'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        fbRecord($invoice, ['amount' => '100000.00'])->assertCreated()->assertJsonPath('data.method', 'manual')->assertJsonPath('data.status', 'succeeded');
        expect($invoice->refresh()->status)->toBe(InvoiceStatus::PartiallyPaid)->and($invoice->amount_paid)->toBe('100000.00');

        fbRecord($invoice, ['amount' => '100000.00'])->assertOk();
        fbRecord($invoice, ['amount' => '185000.00', 'reference' => 'BANK-TRX-1'], 'manual-key-0002')->assertConflict()->assertJsonPath('message', 'A manual payment with this reference is already recorded.');
        fbRecord($invoice, ['amount' => '185000.00', 'reference' => 'BANK-TRX-2', 'evidence_note' => 'تحويل بنكي'], 'manual-key-0003')->assertCreated();

        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($invoice->outstandingAmount())->toBe('0.00')
            ->and($invoice->paid_at)->not->toBeNull();
        fbRecord($invoice, ['amount' => '1.00', 'reference' => 'BANK-TRX-3'], 'manual-key-0004')->assertConflict();

        $entry = AuditLog::query()->where('event', AuditEvent::PaymentRecordedManually->value)->latest('id')->firstOrFail();
        expect($entry->actor_user_id)->toBe($recorder->id)
            ->and($entry->metadata)->toEqual(['invoice_id' => $invoice->id, 'reference' => 'BANK-TRX-2', 'received_on' => '2026-10-05', 'invoice_status' => ['from' => 'partially_paid', 'to' => 'paid']])
            ->and(json_encode($entry->metadata))->not->toContain('185000');
    });

    it('refuses a part payment when the payment terms do not allow one', function () {
        ['invoice' => $invoice] = fbIssuedInvoice(['payment_terms' => ['partial_payments_allowed' => false]]);
        Sanctum::actingAs(financeAdmin(Permission::PaymentsRecord));

        fbRecord($invoice, ['amount' => '1000.00'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        fbRecord($invoice, [])->assertCreated();
        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
    });

    it('refuses manual entries the invoicing policy does not allow, or for money received before the invoice', function () {
        ['invoice' => $invoice] = fbIssuedInvoice(['invoicing' => ['manual_payments_allowed' => false]]);
        Sanctum::actingAs(financeAdmin(Permission::PaymentsRecord));

        fbRecord($invoice, [])->assertConflict()->assertJsonPath('code', 'policy_not_configured')->assertJsonPath('reason_ar', 'سياسة الفوترة التي صدرت بموجبها هذه الفاتورة لا تسمح بتسجيل مدفوعات يدوية.');

        ['invoice' => $other] = fbIssuedInvoice();
        Sanctum::actingAs(financeAdmin(Permission::PaymentsRecord));
        fbRecord($other, ['received_on' => '2026-10-04'])->assertUnprocessable()->assertJsonValidationErrors(['received_on']);
        fbRecord($other, ['received_on' => '2026-10-06'])->assertUnprocessable()->assertJsonValidationErrors(['received_on']);
    });

    it('lets only an administrator granted payments.record enter one', function (Closure $makeUser, int $status) {
        $marketplace = fbIssuedInvoice();
        Sanctum::actingAs($makeUser($marketplace));

        fbRecord($marketplace['invoice'], [])->assertStatus($status);
        expect($marketplace['invoice']->refresh()->status)->toBe($status === 201 ? InvoiceStatus::Paid : InvoiceStatus::Issued);
    })->with([
        'an ordinary IMC administrator' => [fn () => User::factory()->imcAdmin()->create(), 403],
        'a policy approver' => [fn () => financeAdmin(Permission::FinancialPoliciesApprove), 403],
        'the factory that owes it' => [fn (array $m) => $m['factoryMember'], 403],
        'the issuing provider' => [fn (array $m) => $m['providerMembers'][0], 403],
        'a competitor' => [fn (array $m) => $m['providerMembers'][1], 404],
        'another factory' => [fn () => User::factory()->factoryMember()->create(), 404],
        'a payments recorder' => [fn () => financeAdmin(Permission::PaymentsRecord), 201],
    ]);
});

describe('contract templates', function () {
    it('drafts a contract with a snapshot of the agreed terms and the template version, and keeps it after the template changes', function () {
        $first = approvedPolicyVersion(FinancialPolicyKind::ContractTemplate, fbTemplate());
        ['agreement' => $agreement, 'factoryMember' => $factoryMember] = agreedMarketplace();
        Sanctum::actingAs($factoryMember);

        $this->postJson(route('api.v1.agreements.contracts.store', $agreement), fbContract())
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['knowledge_transfer.trainees' => ['The contract template in effect requires at least 3 IMC engineers to be trained.']]);

        $id = $this->postJson(route('api.v1.agreements.contracts.store', $agreement), fbContract(['knowledge_transfer' => ['trainees' => 3, 'training_plan' => 'خطة تدريب تفصيلية']]))
            ->assertCreated()
            ->assertJsonPath('data.legal_status', 'draft_not_binding')
            ->assertJsonPath('data.binding', false)
            ->assertJsonPath('data.policy_basis', 'policy')
            ->assertJsonPath('data.template_version.id', $first->id)
            ->assertJsonPath('data.terms_snapshot.template.clauses.0.heading_ar', 'البند الأول')
            ->assertJsonPath('data.terms_snapshot.agreement.price_amount', $agreement->price_amount)
            ->assertJsonPath('data.terms_snapshot.legal_status', 'draft_not_binding')
            ->json('data.id');

        DB::table('financial_policy_versions')->where('id', $first->id)->update(['effective_to' => '2026-10-05', 'status' => 'superseded']);
        $second = approvedPolicyVersion(FinancialPolicyKind::ContractTemplate, fbTemplate(['clauses' => [['heading_ar' => 'البند المعدّل', 'body_ar' => 'نص جديد']]]), from: '2026-10-06');
        $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00:00', 'UTC'));

        $this->getJson(route('api.v1.contracts.show', $id))
            ->assertJsonPath('data.template_version.id', $first->id)
            ->assertJsonPath('data.terms_snapshot.template.clauses.0.heading_ar', 'البند الأول');

        $this->postJson(route('api.v1.contracts.cancel', $id), ['reason' => 'مراجعة'])->assertOk();
        $this->postJson(route('api.v1.agreements.contracts.store', $agreement), fbContract(['knowledge_transfer' => ['trainees' => 3, 'training_plan' => 'خطة']]))
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.template_version.id', $second->id)
            ->assertJsonPath('data.terms_snapshot.template.clauses.0.heading_ar', 'البند المعدّل');
    });

    it('drafts as before, never binding, while no template is approved', function () {
        ['agreement' => $agreement, 'factoryMember' => $factoryMember] = agreedMarketplace();
        Sanctum::actingAs($factoryMember);

        $this->postJson(route('api.v1.agreements.contracts.store', $agreement), fbContract())
            ->assertCreated()
            ->assertJsonPath('data.template_version', null)
            ->assertJsonPath('data.terms_snapshot.template', null)
            ->assertJsonPath('data.signature.status', 'not_available');
    });

    it('shows IMC the template version but never the terms snapshot', function () {
        approvedPolicyVersion(FinancialPolicyKind::ContractTemplate, fbTemplate());
        ['agreement' => $agreement, 'factoryMember' => $factoryMember] = agreedMarketplace();
        Sanctum::actingAs($factoryMember);
        $id = $this->postJson(route('api.v1.agreements.contracts.store', $agreement), fbContract(['knowledge_transfer' => ['trainees' => 3, 'training_plan' => 'خطة']]))->json('data.id');
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->getJson(route('api.v1.contracts.show', $id))->assertOk()->assertJsonMissingPath('data.terms_snapshot')->assertJsonPath('data.template_version.version', 1);
    });
});

describe('agreements', function () {
    it('records the revenue-share version in effect when the offer is accepted, and never blocks the acceptance', function () {
        $share = approvedPolicyVersion(FinancialPolicyKind::RevenueShare, ['method' => 'percentage', 'rate_percent' => '7', 'base' => 'subtotal_before_tax']);
        ['agreement' => $agreement] = agreedMarketplace();

        expect($agreement->refresh()->policy_basis)->toBe(PolicyBasis::Policy)->and($agreement->revenue_share_policy_version_id)->toBe($share->id);

        withdrawPolicies(FinancialPolicyKind::RevenueShare);
        ['agreement' => $without] = agreedMarketplace();
        expect($without->refresh()->revenue_share_policy_version_id)->toBeNull()->and($without->policy_basis)->toBe(PolicyBasis::Policy);
    });

    it('explains, operation by operation and in Arabic, what blocks the agreement', function () {
        ['agreement' => $agreement, 'factoryMember' => $factoryMember] = agreedMarketplace(imcApproved: false);
        Sanctum::actingAs($factoryMember);

        $response = $this->getJson(route('api.v1.agreements.financial-readiness', $agreement))->assertOk();

        expect($response->json('data.invoice_issuing.available'))->toBeFalse()
            ->and(array_column($response->json('data.invoice_issuing.reasons'), 'code'))->toBe(['agreement_awaiting_imc_review', 'missing_invoicing_policy', 'missing_tax_policy', 'missing_payment_terms_policy'])
            ->and($response->json('data.invoice_issuing.reasons.1.message_ar'))->toBe('لا توجد سياسة «سياسة الفوترة» معتمدة وسارية اليوم تنطبق على هذه الاتفاقية.')
            ->and($response->json('data.contract_signature.reasons.0.decision_needed'))->toBe('OQ-17')
            ->and($response->json('data.payouts.available'))->toBeFalse();

        imcDecision($agreement, AgreementReviewStatus::Approved);
        configureBilling();
        expect($this->getJson(route('api.v1.agreements.financial-readiness', $agreement))->json('data.invoice_issuing'))->toBe(['available' => true, 'reasons' => []]);
    });

    it('answers 404 to anyone outside the agreement', function (Closure $makeUser) {
        ['agreement' => $agreement] = agreedMarketplace();
        Sanctum::actingAs($makeUser());

        $this->getJson(route('api.v1.agreements.financial-readiness', $agreement))->assertNotFound();
    })->with([
        'another factory' => [fn () => User::factory()->factoryMember()->create()],
        'another provider' => [fn () => User::factory()->providerMember()->create()],
    ]);
});

describe('legacy records', function () {
    it('marks records made before the managed policies as legacy, recalculates nothing, and refuses to issue a legacy draft', function () {
        configureBilling();
        ['invoice' => $draft, 'agreement' => $agreement] = draftInvoice();
        DB::table('invoices')->where('id', $draft->id)->update(['policy_basis' => 'legacy', 'invoicing_policy_version_id' => null]);
        DB::table('agreements')->where('id', $agreement->id)->update(['policy_basis' => 'legacy']);
        $issuedId = DB::table('invoices')->insertGetId([
            'agreement_id' => $agreement->id, 'number' => 'OLD-000001', 'status' => 'issued', 'issuer' => 'service_provider', 'currency' => 'EGP',
            'subtotal_amount' => '1000.00', 'tax_rate_percent' => '14.00', 'tax_amount' => '140.00', 'total_amount' => '1140.00',
            'created_by_user_id' => 1, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson(route('api.v1.invoices.show', $issuedId))
            ->assertOk()
            ->assertJsonPath('data.policy_basis', 'legacy')
            ->assertJsonPath('data.policy_versions', ['invoicing' => null, 'tax' => null, 'payment_terms' => null, 'revenue_share' => null])
            ->assertJsonPath('data.calculation', null)
            ->assertJsonPath('data.due_date', null)
            ->assertJsonPath('data.total', '1140.00');
        $this->getJson(route('api.v1.agreements.show', $agreement))->assertJsonPath('data.policy_basis', 'legacy');

        $this->postJson(route('api.v1.invoices.issue', $draft))
            ->assertConflict()
            ->assertJsonPath('message', 'This draft was prepared before financial policies were managed by IMC; cancel it and draft the invoice again.');

        Sanctum::actingAs(financeAdmin(Permission::PaymentsRecord));
        fbRecord(Invoice::query()->findOrFail($issuedId), ['amount' => '1140.00'])->assertConflict()->assertJsonPath('code', 'policy_not_configured');
    });

    it('defaults rows written without a basis to legacy, as the migration marked the existing ones', function () {
        ['agreement' => $agreement] = agreedMarketplace();
        $id = DB::table('contracts')->insertGetId([
            'agreement_id' => $agreement->id, 'version' => 9, 'status' => 'draft', 'knowledge_transfer_trainees' => 2,
            'knowledge_transfer_plan' => 'x', 'drafted_by_user_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        expect(Contract::query()->findOrFail($id)->policy_basis)->toBe(PolicyBasis::Legacy)
            ->and(Contract::query()->findOrFail($id)->contract_template_version_id)->toBeNull();
    });
});

it('runs from an approved agreement through approved policies to a contract draft, an issued invoice and a recorded payment', function () {
    $maker = financeAdmin(Permission::FinancialPoliciesManage);
    $checker = financeAdmin(Permission::FinancialPoliciesApprove);
    $issuer = financeAdmin();
    $recorder = financeAdmin(Permission::PaymentsRecord);
    $policies = [
        'invoicing' => ['issuer' => 'imc', 'payer' => 'factory', 'currency' => 'EGP', 'number_prefix' => 'JZ', 'number_padding' => 6, 'invoice_types' => ['agreement_service'], 'manual_payments_allowed' => true, 'requires_revenue_share' => true],
        'tax' => ['prices_include_tax' => false, 'taxes' => [['code' => 'vat', 'name_ar' => 'ضريبة القيمة المضافة', 'rate_percent' => '14']], 'fees' => [['code' => 'platform', 'name_ar' => 'رسم المنصة', 'calculation' => 'fixed', 'amount' => '500', 'rate_percent' => null, 'taxable' => true]]],
        'payment_terms' => ['due_rule' => 'days_after_issue', 'due_days' => 30, 'partial_payments_allowed' => false],
        'revenue_share' => ['method' => 'percentage', 'rate_percent' => '20', 'base' => 'subtotal_before_tax'],
        'contract_template' => fbTemplate(),
    ];

    foreach ($policies as $kind => $parameters) {
        Sanctum::actingAs($maker);
        $id = test()->postJson(route('api.v1.financial-policies.store'), [
            'kind' => $kind, 'scope_type' => 'global', 'name_ar' => $kind, 'parameters' => $parameters,
            'effective_from' => '2026-10-05', 'effective_to' => null, 'change_reason' => 'قرار الاعتماد',
        ])->assertCreated()->json('data.id');
        test()->postJson(route('api.v1.financial-policy-versions.submit', $id))->assertOk();
        Sanctum::actingAs($checker);
        test()->postJson(route('api.v1.financial-policy-versions.approve', $id))->assertOk();
    }

    ['agreement' => $agreement, 'factoryMember' => $factoryMember] = agreedMarketplace();
    Sanctum::actingAs($factoryMember);
    test()->postJson(route('api.v1.agreements.contracts.store', $agreement), fbContract(['knowledge_transfer' => ['trainees' => 3, 'training_plan' => 'خطة']]))
        ->assertCreated()->assertJsonPath('data.binding', false);

    Sanctum::actingAs($issuer);
    $invoiceId = test()->postJson(route('api.v1.agreements.invoices.store', $agreement))->assertCreated()->json('data.id');
    test()->postJson(route('api.v1.invoices.issue', $invoiceId))
        ->assertOk()
        ->assertJsonPath('data.subtotal', '250000.00')
        ->assertJsonPath('data.fees', '500.00')
        ->assertJsonPath('data.tax.amount', '35070.00')
        ->assertJsonPath('data.total', '285570.00')
        ->assertJsonPath('data.revenue_share.amount', '50000.00')
        ->assertJsonPath('data.due_date', '2026-11-04');

    Sanctum::actingAs($recorder);
    test()->postJson(route('api.v1.invoices.manual-payments.store', $invoiceId), ['amount' => '285570.00', 'received_on' => '2026-10-05', 'reference' => 'TRX-77'], ['Idempotency-Key' => 'integration-1'])
        ->assertCreated();

    Sanctum::actingAs($factoryMember);
    test()->getJson(route('api.v1.invoices.show', $invoiceId))->assertJsonPath('data.status', 'paid')->assertJsonPath('data.outstanding', '0.00');
    expect(FinancialPolicyVersion::query()->count())->toBe(5)
        ->and(Agreement::query()->findOrFail($agreement->id)->revenue_share_policy_version_id)->not->toBeNull();
});
