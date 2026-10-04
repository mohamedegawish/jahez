<?php

use App\Billing\PolicyCalendar;
use App\Enums\FinancialPolicyKind;
use App\Enums\InvoiceStatus;
use App\Models\CatalogService;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('while no financial policy is approved (OQ-16, ADR-023)', function () {
    it('refuses to draft an invoice, saying in Arabic which policy is missing', function () {
        ['providerMembers' => $providerMembers, 'agreement' => $agreement] = agreedMarketplace();
        Sanctum::actingAs($providerMembers[0]);
        $today = PolicyCalendar::today()->toDateString();

        $this->postJson(route('api.v1.agreements.invoices.store', $agreement))
            ->assertConflict()
            ->assertJsonPath('code', 'policy_not_configured')
            ->assertJsonPath('decision_needed', 'OQ-16')
            ->assertJsonPath('missing_policies', ['invoicing'])
            ->assertJsonPath('message', "No approved invoicing policy applies on {$today}, so it is not possible to draft an invoice.")
            ->assertJsonPath('reason_ar', fn (string $reason): bool => str_contains($reason, '«سياسة الفوترة»') && str_contains($reason, 'لا يمكن إعداد مسودة فاتورة'));
        $this->assertDatabaseCount('invoices', 0);
    });

    it('refuses to issue while a needed policy is not approved, listing every missing one', function (Closure $withdraw, array $missing) {
        configureBilling();
        ['invoice' => $invoice] = draftInvoice();
        $withdraw();

        $this->postJson(route('api.v1.invoices.issue', $invoice))
            ->assertConflict()
            ->assertJsonPath('code', 'policy_not_configured')
            ->assertJsonPath('missing_policies', $missing);
        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft)->and($invoice->number)->toBeNull();
    })->with([
        'no tax policy' => [fn () => withdrawPolicies(FinancialPolicyKind::Tax), ['tax']],
        'no payment terms' => [fn () => withdrawPolicies(FinancialPolicyKind::PaymentTerms), ['payment_terms']],
        'neither' => [fn () => withdrawPolicies(FinancialPolicyKind::Tax, FinancialPolicyKind::PaymentTerms), ['tax', 'payment_terms']],
        'a required revenue share' => [fn () => configureBilling(['invoicing' => ['requires_revenue_share' => true]]), ['revenue_share']],
    ]);

    it('tells clients which billing operations are available', function () {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson(route('api.v1.billing.configuration'));

        $response->assertOk()
            ->assertJsonPath('data.invoice_drafting', ['available' => false, 'decision_needed' => 'OQ-16'])
            ->assertJsonPath('data.revenue_share', ['available' => false, 'decision_needed' => 'OQ-15'])
            ->assertJsonPath('data.payments.available', false)
            ->assertJsonPath('data.payouts.available', false);
    });
});

describe('drafting', function () {
    beforeEach(fn () => configureBilling());

    it('drafts the agreed service at the agreed price, with no number, tax or total yet', function () {
        ['invoice' => $invoice] = draftInvoice();

        $response = $this->getJson(route('api.v1.invoices.show', $invoice));

        $response->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.issuer', 'service_provider')
            ->assertJsonPath('data.number', null)
            ->assertJsonPath('data.currency', 'EGP')
            ->assertJsonPath('data.lines.0.description', CatalogService::query()->where('code', 'erp_business_applications.01')->value('name_ar'))
            ->assertJsonPath('data.lines.0.quantity', 1)
            ->assertJsonPath('data.lines.0.unit_amount', '250000.00')
            ->assertJsonPath('data.subtotal', '250000.00')
            ->assertJsonPath('data.tax', null)
            ->assertJsonPath('data.total', null)
            ->assertJsonPath('data.revenue_share.status', 'not_configured');
    });

    it('lets only the configured issuer draft', function (string $issuer, Closure $makeDrafter, int $status) {
        configureBilling(['invoicing' => ['issuer' => $issuer]]);
        $marketplace = agreedMarketplace();
        Sanctum::actingAs($makeDrafter($marketplace));

        $this->postJson(route('api.v1.agreements.invoices.store', $marketplace['agreement']))->assertStatus($status);
    })->with([
        'provider issuer: the provider' => ['service_provider', fn (array $m) => $m['providerMembers'][0], 201],
        'provider issuer: the factory' => ['service_provider', fn (array $m) => $m['factoryMember'], 403],
        'provider issuer: IMC' => ['service_provider', fn () => User::factory()->imcAdmin()->create(), 403],
        'IMC issuer: IMC' => ['imc', fn () => User::factory()->imcAdmin()->create(), 201],
        'IMC issuer: the provider' => ['imc', fn (array $m) => $m['providerMembers'][0], 403],
        'a competitor' => ['service_provider', fn (array $m) => $m['providerMembers'][1], 404],
        'another factory' => ['imc', fn () => User::factory()->factoryMember()->create(), 404],
    ]);

    it('keeps one invoice per agreement until it is cancelled', function () {
        ['agreement' => $agreement, 'invoice' => $invoice] = draftInvoice();

        $this->postJson(route('api.v1.agreements.invoices.store', $agreement))
            ->assertConflict()
            ->assertJsonPath('message', "This agreement already has an invoice (id {$invoice->id}, draft).");

        $this->postJson(route('api.v1.invoices.cancel', $invoice), ['reason' => 'Wrong lines'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson(route('api.v1.agreements.invoices.store', $agreement))->assertCreated();
    });
});

describe('lines', function () {
    beforeEach(fn () => configureBilling());

    it('adds and removes lines, with the subtotal computed exactly', function () {
        ['invoice' => $invoice] = draftInvoice();

        $response = $this->postJson(route('api.v1.invoices.lines.store', $invoice), ['description' => 'On-site training days', 'quantity' => 3, 'unit_amount' => '1000.10']);

        $response->assertOk()
            ->assertJsonPath('data.lines.1.line_amount', '3000.30')
            ->assertJsonPath('data.subtotal', '253000.30');
        $lineId = $response->json('data.lines.1.id');
        $this->deleteJson(route('api.v1.invoices.lines.destroy', [$invoice, $lineId]))->assertOk()->assertJsonPath('data.subtotal', '250000.00');
    });

    it('rejects an invalid line', function (array $line, string $field) {
        ['invoice' => $invoice] = draftInvoice();

        $this->postJson(route('api.v1.invoices.lines.store', $invoice), ['description' => 'Extra', 'quantity' => 1, 'unit_amount' => '10.00', ...$line])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with([
        'three decimals' => [['unit_amount' => '10.005'], 'unit_amount'],
        'negative amount' => [['unit_amount' => '-1'], 'unit_amount'],
        'text amount' => [['unit_amount' => 'ten'], 'unit_amount'],
        'fractional quantity' => [['quantity' => 1.5], 'quantity'],
        'zero quantity' => [['quantity' => 0], 'quantity'],
        'no description' => [['description' => ''], 'description'],
    ]);

    it('refuses a total beyond what DECIMAL(14,2) holds', function () {
        ['invoice' => $invoice] = draftInvoice();

        $this->postJson(route('api.v1.invoices.lines.store', $invoice), ['description' => 'Huge', 'quantity' => 1000, 'unit_amount' => '999999999999.99'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);
    });

    it('returns 404 for a line of another invoice, and 403 to the factory', function () {
        ['invoice' => $invoice] = draftInvoice();
        ['invoice' => $otherInvoice, 'factoryMember' => $otherFactory] = draftInvoice();
        $otherLine = $otherInvoice->lines()->firstOrFail();

        $this->deleteJson(route('api.v1.invoices.lines.destroy', [$invoice, $otherLine]))->assertNotFound();
        Sanctum::actingAs($otherFactory);
        $this->postJson(route('api.v1.invoices.lines.store', $otherInvoice), ['description' => 'x', 'quantity' => 1, 'unit_amount' => '1'])->assertForbidden();
    });
});

describe('issuing', function () {
    beforeEach(fn () => configureBilling());

    it('numbers the invoice, and fixes the tax (half up) and the total exactly', function () {
        ['invoice' => $invoice] = draftInvoice();
        $this->deleteJson(route('api.v1.invoices.lines.destroy', [$invoice, $invoice->lines()->firstOrFail()]))->assertOk();
        $this->postJson(route('api.v1.invoices.lines.store', $invoice), ['description' => 'Licence', 'quantity' => 1, 'unit_amount' => '1000.05'])->assertOk();

        $response = $this->postJson(route('api.v1.invoices.issue', $invoice));

        $response->assertOk()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.number', 'JZ-000001')
            ->assertJsonPath('data.subtotal', '1000.05')
            ->assertJsonPath('data.tax', ['rate_percent' => '14.00', 'amount' => '140.01'])
            ->assertJsonPath('data.total', '1140.06');
        $this->postJson(route('api.v1.invoices.lines.store', $invoice), ['description' => 'Late', 'quantity' => 1, 'unit_amount' => '1'])->assertConflict();
        $this->postJson(route('api.v1.invoices.issue', $invoice))->assertConflict();
    });

    it('numbers invoices without gaps, per prefix', function () {
        ['invoice' => $first] = draftInvoice();
        $this->postJson(route('api.v1.invoices.issue', $first))->assertOk();
        ['invoice' => $second] = draftInvoice();

        expect($this->postJson(route('api.v1.invoices.issue', $second))->json('data.number'))->toBe('JZ-000002');
    });

    it('shows IMC\'s revenue share only once the owner sets it (OQ-15), and pays nothing out', function () {
        configureBilling(['revenue_share' => ['rate_percent' => '20']]);
        ['invoice' => $invoice] = draftInvoice();

        $this->postJson(route('api.v1.invoices.issue', $invoice))
            ->assertOk()
            ->assertJsonPath('data.revenue_share', ['status' => 'calculated', 'rate_percent' => '20.00', 'amount' => '50000.00']);
    });

    it('refuses to issue an invoice with nothing to pay', function () {
        ['invoice' => $invoice] = draftInvoice();
        $this->deleteJson(route('api.v1.invoices.lines.destroy', [$invoice, $invoice->lines()->firstOrFail()]))->assertOk();

        $this->postJson(route('api.v1.invoices.issue', $invoice))->assertUnprocessable()->assertJsonValidationErrors(['lines']);
    });

    it('cannot cancel an issued invoice until credit notes are decided', function () {
        ['invoice' => $invoice] = draftInvoice();
        $this->postJson(route('api.v1.invoices.issue', $invoice))->assertOk();

        $this->postJson(route('api.v1.invoices.cancel', $invoice))
            ->assertConflict()
            ->assertJsonPath('code', 'policy_not_configured')
            ->assertJsonPath('decision_needed', 'OQ-16');
    });

    it('locks the invoice, share-locks the policies it is issued under, then the number sequence', function () {
        ['invoice' => $invoice] = draftInvoice();

        $reads = lockingReads(fn () => $this->postJson(route('api.v1.invoices.issue', $invoice))->assertOk());

        expect($reads)->toBe(['invoices:update', ...array_fill(0, 4, 'financial_policies:share'), 'invoice_number_sequences:update']);
    });
});

describe('visibility', function () {
    beforeEach(fn () => configureBilling());

    it('shows the invoice to both parties and to IMC billing oversight, and 404 to anyone else', function (Closure $makeUser, int $status) {
        $marketplace = draftInvoice();
        Sanctum::actingAs($makeUser($marketplace));

        $this->getJson(route('api.v1.invoices.show', $marketplace['invoice']))->assertStatus($status);
    })->with([
        'the factory' => [fn (array $m) => $m['factoryMember'], 200],
        'the provider' => [fn (array $m) => $m['providerMembers'][0], 200],
        'IMC administrator' => [fn () => User::factory()->imcAdmin()->create(), 200],
        'a competitor' => [fn (array $m) => $m['providerMembers'][1], 404],
        'another factory' => [fn () => User::factory()->factoryMember()->create(), 404],
    ]);

    it('lists each party only its own invoices', function () {
        ['invoice' => $invoice, 'factoryMember' => $member] = draftInvoice();
        draftInvoice();
        Sanctum::actingAs($member);

        expect($this->getJson(route('api.v1.invoices.index'))->json('data.*.id'))->toBe([$invoice->id]);
    });
});

it('reports the billing operations available once the owner sets the rules', function () {
    configureBilling(['revenue_share' => ['rate_percent' => '20']]);
    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.v1.billing.configuration'))
        ->assertJsonPath('data.invoice_drafting', ['available' => true, 'decision_needed' => null])
        ->assertJsonPath('data.invoice_issuing', ['available' => true, 'decision_needed' => null])
        ->assertJsonPath('data.revenue_share', ['available' => true, 'decision_needed' => null])
        ->assertJsonPath('data.payments', ['available' => false, 'decision_needed' => 'OQ-16'])
        ->assertJsonPath('data.refund_initiation.available', false);
});
