<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\Money;
use App\Billing\PolicyCalendar;
use App\Billing\PolicyNotConfiguredException;
use App\Enums\AuditEvent;
use App\Enums\FinancialPolicyKind;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PolicyBasis;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecordManualPaymentRequest;
use App\Http\Resources\V1\PaymentResource;
use App\Models\AuditLog;
use App\Models\FinancialPolicyVersion;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Authorised, audited manual payment entries (ADR-023): the only way an invoice is
 * marked paid without gateway evidence. Allowed only when the invoicing policy the
 * invoice was issued under permits manual payments, for at most what is outstanding,
 * and for less only when its payment terms allow part payments. No money moves here:
 * the entry records money that already arrived, with its evidence reference.
 */
class ManualPaymentController extends Controller
{
    public function store(RecordManualPaymentRequest $request, Invoice $invoice, #[CurrentUser] User $user): JsonResponse
    {
        $key = $request->idempotencyKey();
        $amount = Money::fromMinor(Money::toMinor($request->string('amount')->toString()));
        $reference = $request->string('reference')->toString();

        [$payment, $created] = DB::transaction(function () use ($request, $invoice, $user, $key, $amount, $reference): array {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $existing = $locked->payments()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return [$existing, false];
            }

            if (! in_array($locked->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true)) {
                throw new ConflictHttpException("This invoice is {$locked->status->value}; a payment can be recorded only for an issued or partially paid invoice.");
            }
            if ($locked->payments()->where('status', PaymentStatus::Pending)->exists()) {
                throw new ConflictHttpException('A gateway payment of this invoice is in progress; wait for its result before recording a manual payment.');
            }

            $invoicing = $locked->policy_basis === PolicyBasis::Policy ? FinancialPolicyVersion::query()->find($locked->invoicing_policy_version_id) : null;
            if ($invoicing === null || $invoicing->parameters['manual_payments_allowed'] !== true) {
                throw new PolicyNotConfiguredException(
                    'The invoicing policy this invoice was issued under does not allow manual payment entries.',
                    'OQ-16',
                    'سياسة الفوترة التي صدرت بموجبها هذه الفاتورة لا تسمح بتسجيل مدفوعات يدوية.',
                    [FinancialPolicyKind::Invoicing->value],
                );
            }

            $outstandingMinor = Money::toMinor($locked->outstandingAmount());
            $amountMinor = Money::toMinor($amount);
            $terms = FinancialPolicyVersion::query()->find($locked->payment_terms_policy_version_id);
            if ($amountMinor > $outstandingMinor) {
                throw ValidationException::withMessages(['amount' => "The amount is more than the {$locked->outstandingAmount()} outstanding on this invoice."]);
            }
            if ($amountMinor < $outstandingMinor && ($terms === null || $terms->parameters['partial_payments_allowed'] !== true)) {
                throw ValidationException::withMessages(['amount' => "The payment terms of this invoice do not allow part payments; record the full {$locked->outstandingAmount()} outstanding."]);
            }
            if ($locked->issued_at !== null && $request->string('received_on')->toString() < PolicyCalendar::dayOf($locked->issued_at)->toDateString()) {
                throw ValidationException::withMessages(['received_on' => 'The money cannot have been received before the invoice was issued.']);
            }
            if (Payment::query()->where('gateway', Payment::METHOD_MANUAL)->where('gateway_reference', $reference)->exists()) {
                throw new ConflictHttpException('A manual payment with this reference is already recorded.');
            }

            $payment = new Payment;
            $payment->invoice_id = $locked->id;
            $payment->method = Payment::METHOD_MANUAL;
            $payment->gateway = Payment::METHOD_MANUAL;
            $payment->gateway_reference = $reference;
            $payment->idempotency_key = $key;
            $payment->status = PaymentStatus::Succeeded;
            $payment->amount = $amount;
            $payment->currency = $locked->currency;
            $payment->initiated_by_user_id = $user->id;
            $payment->recorded_by_user_id = $user->id;
            $payment->received_on = PolicyCalendar::parse($request->string('received_on')->toString());
            $payment->evidence_note = $request->filled('evidence_note') ? $request->string('evidence_note')->toString() : null;
            $payment->status_changed_at = now();
            $payment->save();

            $before = $locked->status;
            $after = $locked->applyPayment($amount);

            AuditLog::record(AuditEvent::PaymentRecordedManually, $user, $payment, [
                'invoice_id' => $locked->id,
                'reference' => $reference,
                'received_on' => $request->string('received_on')->toString(),
                'invoice_status' => ['from' => $before->value, 'to' => $after->value],
            ]);
            if ($after === InvoiceStatus::Paid) {
                MarketplaceNotifications::invoicePaid($locked, $locked->agreement()->firstOrFail());
            }

            return [$payment, true];
        });

        return (new PaymentResource($payment->refresh()))
            ->response()
            ->setStatusCode($created ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK);
    }
}
