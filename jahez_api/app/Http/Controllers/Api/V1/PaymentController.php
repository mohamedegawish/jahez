<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\PaymentGatewayManager;
use App\Enums\AuditEvent;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StartPaymentRequest;
use App\Http\Resources\V1\PaymentResource;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Payments of issued invoices through the configured gateway (ADR-017). Starting a
 * payment never marks it successful: only verified gateway evidence does
 * (App\Billing\PaymentProcessor).
 */
class PaymentController extends Controller
{
    public function index(Invoice $invoice): AnonymousResourceCollection
    {
        Gate::authorize('view', $invoice);

        return PaymentResource::collection($invoice->payments()->get());
    }

    /**
     * Start paying what is outstanding on an issued or partially paid invoice. The same Idempotency-Key returns the same payment
     * (200) instead of a second charge; the invoice row is locked, so two concurrent
     * starts cannot both create one. The gateway is called after the payment is saved
     * and outside the lock; if it fails, the payment is marked failed and 503 is returned.
     */
    public function store(StartPaymentRequest $request, Invoice $invoice, #[CurrentUser] User $user): JsonResponse
    {
        $gateway = PaymentGatewayManager::gateway();
        $gatewayKey = (string) PaymentGatewayManager::configuredKey();
        $key = $request->idempotencyKey();

        [$payment, $created] = DB::transaction(function () use ($invoice, $user, $gatewayKey, $key): array {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $existing = $locked->payments()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            if (! in_array($locked->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true)) {
                throw new ConflictHttpException("This invoice is {$locked->status->value}; only an issued or partially paid invoice can be paid.");
            }

            if ($locked->payments()->where('status', PaymentStatus::Pending)->exists()) {
                throw new ConflictHttpException('This invoice already has a payment in progress.');
            }

            $payment = new Payment;
            $payment->invoice_id = $locked->id;
            $payment->gateway = $gatewayKey;
            $payment->idempotency_key = $key;
            $payment->amount = $locked->outstandingAmount();
            $payment->currency = $locked->currency;
            $payment->initiated_by_user_id = $user->id;
            $payment->save();

            AuditLog::record(AuditEvent::PaymentInitiated, $user, $payment, ['invoice_id' => $locked->id, 'gateway' => $gatewayKey]);

            return [$payment, true];
        });

        if ($created) {
            try {
                $checkout = $gateway->initiate($payment);
            } catch (Throwable $exception) {
                report($exception);
                $payment->status = PaymentStatus::Failed;
                $payment->failure_reason = 'gateway_unavailable';
                $payment->status_changed_at = now();
                $payment->save();

                throw new HttpException(503, 'The payment gateway could not start the payment. Try again with a new Idempotency-Key.');
            }

            $payment->gateway_reference = $checkout->reference;
            $payment->checkout_url = $checkout->checkoutUrl;
            $payment->save();
        }

        return (new PaymentResource($payment->refresh()))
            ->response()
            ->setStatusCode($created ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK);
    }
}
