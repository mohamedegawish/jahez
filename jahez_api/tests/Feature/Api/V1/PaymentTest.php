<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakePaymentGateway;

beforeEach(function () {
    FakePaymentGateway::reset();
    configureBilling([
        'jahez.billing.payment_gateway' => 'fake',
        'jahez.billing.gateways' => ['fake' => FakePaymentGateway::class],
    ]);
});

/**
 * An issued invoice (250,000.00 + 14% = 285,000.00 EGP), with the factory signed in.
 *
 * @return array{factoryMember: User, providerMembers: list<User>, invoice: Invoice}
 */
function issuedInvoice(): array
{
    $marketplace = draftInvoice();
    test()->postJson(route('api.v1.invoices.issue', $marketplace['invoice']))->assertOk();
    Sanctum::actingAs($marketplace['factoryMember']);

    return [...$marketplace, 'invoice' => $marketplace['invoice']->refresh()];
}

/**
 * Sends a callback signed like the fake gateway signs it.
 *
 * @param  array<string, string>  $body
 */
function gatewayCallback(array $body, ?string $signature = null): TestResponse
{
    [$json, $validSignature] = FakePaymentGateway::signedCallback($body);

    return test()->call('POST', route('api.v1.payment-gateways.callback', 'fake'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FAKE_SIGNATURE' => $signature ?? $validSignature,
    ], $json);
}

function startPayment(Invoice $invoice, string $key = 'pay-attempt-0001'): TestResponse
{
    return test()->postJson(route('api.v1.invoices.payments.store', $invoice), [], ['Idempotency-Key' => $key]);
}

describe('starting a payment', function () {
    it('refuses while no gateway is configured (OQ-16)', function () {
        ['invoice' => $invoice] = issuedInvoice();
        config(['jahez.billing.payment_gateway' => null]);

        startPayment($invoice)
            ->assertConflict()
            ->assertJsonPath('code', 'policy_not_configured')
            ->assertJsonPath('message', 'No payment gateway is configured, so payments cannot be started.');
        $this->assertDatabaseCount('payments', 0);
    });

    it('starts a pending payment of the invoice total, never a successful one', function () {
        ['invoice' => $invoice] = issuedInvoice();

        $response = startPayment($invoice);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', '285000.00')
            ->assertJsonPath('data.currency', 'EGP')
            ->assertJsonPath('data.gateway', 'fake')
            ->assertJsonPath('data.checkout_url', 'https://pay.example.test/checkout/fake-'.$response->json('data.id'));
        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Issued);
    });

    it('returns the same payment for a repeated Idempotency-Key, and charges once', function () {
        ['invoice' => $invoice] = issuedInvoice();
        $first = startPayment($invoice)->assertCreated()->json('data.id');

        startPayment($invoice)->assertOk()->assertJsonPath('data.id', $first);

        expect(FakePaymentGateway::$initiateCalls)->toBe(1)
            ->and(Payment::query()->count())->toBe(1);
        startPayment($invoice, 'another-key-0002')
            ->assertConflict()
            ->assertJsonPath('message', 'This invoice already has a payment in progress.');
    });

    it('requires a well-formed Idempotency-Key', function (?string $key) {
        ['invoice' => $invoice] = issuedInvoice();

        $this->postJson(route('api.v1.invoices.payments.store', $invoice), [], $key === null ? [] : ['Idempotency-Key' => $key])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key']);
    })->with([
        'missing' => [null],
        'too short' => ['abc'],
        'with spaces' => ['pay attempt 0001'],
    ]);

    it('marks the payment failed and answers 503 when the gateway cannot start it, then allows a new attempt', function () {
        ['invoice' => $invoice] = issuedInvoice();
        FakePaymentGateway::$failNextInitiate = true;

        startPayment($invoice)->assertStatus(503)->assertJsonPath('code', 'service_unavailable');

        expect(Payment::query()->sole()->status)->toBe(PaymentStatus::Failed)
            ->and(Payment::query()->sole()->failure_reason)->toBe('gateway_unavailable');
        startPayment($invoice, 'second-attempt-02')->assertCreated();
    });

    it('lets only the factory pay, and only an issued invoice', function () {
        ['invoice' => $invoice, 'providerMembers' => $providerMembers] = issuedInvoice();
        ['invoice' => $draft, 'factoryMember' => $otherFactory] = draftInvoice();

        Sanctum::actingAs($providerMembers[0]);
        startPayment($invoice)->assertForbidden();
        Sanctum::actingAs($providerMembers[1]);
        startPayment($invoice)->assertNotFound();
        Sanctum::actingAs($otherFactory);
        startPayment($draft)->assertConflict()->assertJsonPath('message', 'This invoice is draft; only an issued or partially paid invoice can be paid.');
    });
});

describe('gateway callbacks', function () {
    it('applies a verified success: the payment succeeds and the invoice is paid', function () {
        ['invoice' => $invoice] = issuedInvoice();
        $payment = Payment::query()->findOrFail(startPayment($invoice)->json('data.id'));

        gatewayCallback(['event_id' => 'evt-1', 'reference' => "fake-{$payment->id}", 'status' => 'paid', 'amount' => '285000.00', 'currency' => 'EGP'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'applied');

        expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
            ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($invoice->paid_at)->not->toBeNull();
    });

    it('records a repeated callback once and applies it once', function () {
        ['invoice' => $invoice] = issuedInvoice();
        $payment = Payment::query()->findOrFail(startPayment($invoice)->json('data.id'));
        $body = ['event_id' => 'evt-1', 'reference' => "fake-{$payment->id}", 'status' => 'paid', 'amount' => '285000.00', 'currency' => 'EGP'];

        gatewayCallback($body)->assertJsonPath('data.outcome', 'applied');
        gatewayCallback($body)->assertOk()->assertJsonPath('data.outcome', 'duplicate');

        expect(PaymentEvent::query()->count())->toBe(1);
    });

    it('refuses an unverified callback with 400 and changes nothing', function () {
        ['invoice' => $invoice] = issuedInvoice();
        $payment = Payment::query()->findOrFail(startPayment($invoice)->json('data.id'));

        gatewayCallback(['event_id' => 'evt-1', 'reference' => "fake-{$payment->id}", 'status' => 'paid', 'amount' => '285000.00', 'currency' => 'EGP'], 'forged-signature')
            ->assertStatus(400)
            ->assertJsonPath('code', 'bad_request');

        expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
            ->and(PaymentEvent::query()->count())->toBe(0);
    });

    it('records but does not apply an evidence that does not fit', function (Closure $body, string $outcome) {
        ['invoice' => $invoice] = issuedInvoice();
        $payment = Payment::query()->findOrFail(startPayment($invoice)->json('data.id'));

        gatewayCallback($body($payment))->assertOk()->assertJsonPath('data.outcome', $outcome);

        expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
            ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Issued);
    })->with([
        'another amount' => [fn (Payment $p) => ['event_id' => 'evt-1', 'reference' => "fake-{$p->id}", 'status' => 'paid', 'amount' => '284999.99', 'currency' => 'EGP'], 'amount_mismatch'],
        'another currency' => [fn (Payment $p) => ['event_id' => 'evt-1', 'reference' => "fake-{$p->id}", 'status' => 'paid', 'amount' => '285000.00', 'currency' => 'USD'], 'amount_mismatch'],
        'an unknown payment' => [fn () => ['event_id' => 'evt-1', 'reference' => 'fake-999999', 'status' => 'paid', 'amount' => '285000.00', 'currency' => 'EGP'], 'unknown_payment'],
        'still pending' => [fn (Payment $p) => ['event_id' => 'evt-1', 'reference' => "fake-{$p->id}", 'status' => 'pending', 'amount' => '285000.00', 'currency' => 'EGP'], 'no_change'],
    ]);

    it('ignores a transition the payment lifecycle does not allow', function () {
        ['invoice' => $invoice] = issuedInvoice();
        $payment = Payment::query()->findOrFail(startPayment($invoice)->json('data.id'));
        gatewayCallback(['event_id' => 'evt-1', 'reference' => "fake-{$payment->id}", 'status' => 'declined', 'amount' => '285000.00', 'currency' => 'EGP', 'failure_reason' => 'insufficient_funds'])
            ->assertJsonPath('data.outcome', 'applied');

        gatewayCallback(['event_id' => 'evt-2', 'reference' => "fake-{$payment->id}", 'status' => 'paid', 'amount' => '285000.00', 'currency' => 'EGP'])
            ->assertJsonPath('data.outcome', 'ignored_transition');

        expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
            ->and($payment->failure_reason)->toBe('insufficient_funds')
            ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Issued);
    });

    it('mirrors a refund the gateway reports on the invoice', function () {
        ['invoice' => $invoice] = issuedInvoice();
        $payment = Payment::query()->findOrFail(startPayment($invoice)->json('data.id'));
        gatewayCallback(['event_id' => 'evt-1', 'reference' => "fake-{$payment->id}", 'status' => 'paid', 'amount' => '285000.00', 'currency' => 'EGP']);

        gatewayCallback(['event_id' => 'evt-2', 'reference' => "fake-{$payment->id}", 'status' => 'refunded', 'amount' => '285000.00', 'currency' => 'EGP'])
            ->assertJsonPath('data.outcome', 'applied');

        expect($payment->refresh()->status)->toBe(PaymentStatus::Refunded)
            ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Refunded);
    });

    it('answers 404 for any gateway but the configured one, and for none', function () {
        $this->postJson(route('api.v1.payment-gateways.callback', 'stripe'), [])->assertNotFound();
        config(['jahez.billing.payment_gateway' => null]);
        $this->postJson(route('api.v1.payment-gateways.callback', 'fake'), [])->assertNotFound();
    });

    it('locks the invoice before the payment', function () {
        ['invoice' => $invoice] = issuedInvoice();
        $payment = Payment::query()->findOrFail(startPayment($invoice)->json('data.id'));

        $reads = lockingReads(fn () => gatewayCallback(['event_id' => 'evt-1', 'reference' => "fake-{$payment->id}", 'status' => 'paid', 'amount' => '285000.00', 'currency' => 'EGP'])->assertOk());

        expect($reads)->toBe(['invoices:update', 'payments:update']);
    });
});

describe('reconciliation', function () {
    it('applies the status the gateway reports for payments pending too long', function () {
        ['invoice' => $invoice] = issuedInvoice();
        $payment = Payment::query()->findOrFail(startPayment($invoice)->json('data.id'));
        FakePaymentGateway::$statuses["fake-{$payment->id}"] = PaymentStatus::Succeeded;

        Artisan::call('payments:reconcile');
        expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);

        $this->travel(16)->minutes();
        Artisan::call('payments:reconcile');

        expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
            ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
            ->and(PaymentEvent::query()->sole()->source)->toBe('reconciliation');
    });

    it('does nothing while no gateway is configured', function () {
        config(['jahez.billing.payment_gateway' => null]);

        expect(Artisan::call('payments:reconcile'))->toBe(0)
            ->and(Artisan::output())->toContain('No payment gateway is configured');
    });
});

it('lists an invoice\'s payments to both parties', function () {
    ['invoice' => $invoice, 'providerMembers' => $providerMembers] = issuedInvoice();
    $paymentId = startPayment($invoice)->json('data.id');
    Sanctum::actingAs($providerMembers[0]);

    expect($this->getJson(route('api.v1.invoices.payments.index', $invoice))->json('data.*.id'))->toBe([$paymentId]);
});
