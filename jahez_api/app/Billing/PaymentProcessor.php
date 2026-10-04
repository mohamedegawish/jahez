<?php

namespace App\Billing;

use App\Enums\AuditEvent;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Applies verified gateway evidence to payments and invoices (ADR-017): the only way a
 * payment leaves `pending`, an invoice becomes `paid`, or a paid invoice becomes
 * `refunded`. Each gateway event is recorded once (unique per gateway) before anything
 * changes, so a repeated callback is applied at most once. A reported amount or currency
 * that differs from the payment's changes nothing. Locks: the invoice row, then the
 * payment row.
 */
final class PaymentProcessor
{
    public const SOURCE_CALLBACK = 'callback';

    public const SOURCE_RECONCILIATION = 'reconciliation';

    /**
     * @return string the PaymentEvent outcome
     */
    public static function apply(GatewayEvent $event, string $gateway, string $source, ?string $ipAddress = null): string
    {
        return DB::transaction(function () use ($event, $gateway, $source, $ipAddress): string {
            $record = new PaymentEvent;
            $record->gateway = $gateway;
            $record->event_id = $event->eventId;
            $record->gateway_reference = $event->reference;
            $record->reported_status = $event->status;
            $record->reported_amount = $event->amount;
            $record->reported_currency = $event->currency;
            $record->source = $source;
            $record->outcome = 'processing';

            try {
                $record->save();
            } catch (UniqueConstraintViolationException) {
                return PaymentEvent::OUTCOME_DUPLICATE;
            }

            $outcome = self::applyToPayment($event, $gateway, $record, $ipAddress);
            $record->outcome = $outcome;
            $record->save();

            return $outcome;
        });
    }

    private static function applyToPayment(GatewayEvent $event, string $gateway, PaymentEvent $record, ?string $ipAddress): string
    {
        $foundId = Payment::query()->where('gateway', $gateway)->where('gateway_reference', $event->reference)->value('id');
        if ($foundId === null) {
            return PaymentEvent::OUTCOME_UNKNOWN_PAYMENT;
        }

        $paymentId = (int) $foundId;
        $invoiceId = (int) Payment::query()->whereKey($paymentId)->value('invoice_id');
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
        $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
        $record->payment_id = $payment->id;

        if (Money::toMinor($event->amount) !== Money::toMinor($payment->amount) || $event->currency !== $payment->currency) {
            return PaymentEvent::OUTCOME_AMOUNT_MISMATCH;
        }

        if ($event->status === $payment->status) {
            return PaymentEvent::OUTCOME_NO_CHANGE;
        }

        if (! $payment->status->canBecome($event->status)) {
            return PaymentEvent::OUTCOME_IGNORED_TRANSITION;
        }

        // A part payment recorded while this one was pending is impossible (both refuse a
        // pending payment), so this only guards against applying more than is owed.
        if ($event->status === PaymentStatus::Succeeded && Money::toMinor($payment->amount) > Money::toMinor($invoice->outstandingAmount())) {
            return PaymentEvent::OUTCOME_AMOUNT_MISMATCH;
        }

        $previous = $payment->status;
        $payment->status = $event->status;
        $payment->status_changed_at = now();
        $payment->failure_reason = $event->status === PaymentStatus::Failed ? ($event->failureReason ?? 'declined') : $payment->failure_reason;
        $payment->save();

        $invoiceChange = null;
        if ($event->status === PaymentStatus::Succeeded && $invoice->status->canBecome(InvoiceStatus::Paid)) {
            $before = $invoice->status;
            $after = $invoice->applyPayment($payment->amount);
            $invoiceChange = $after !== $before ? $after : null;
        } elseif ($event->status === PaymentStatus::Refunded && $invoice->status->canBecome(InvoiceStatus::Refunded)) {
            $invoiceChange = InvoiceStatus::Refunded;
            $invoice->moveTo($invoiceChange);
        }
        if ($invoiceChange !== null) {
            if ($invoiceChange === InvoiceStatus::Paid) {
                MarketplaceNotifications::invoicePaid($invoice, $invoice->agreement()->firstOrFail());
            }
        }

        AuditLog::record(AuditEvent::PaymentStatusChanged, null, $payment, [
            'invoice_id' => $invoice->id,
            'from' => $previous->value,
            'to' => $payment->status->value,
            'source' => $record->source,
            'gateway_event_id' => $event->eventId,
            'invoice_status' => $invoiceChange?->value,
        ], $ipAddress);

        return PaymentEvent::OUTCOME_APPLIED;
    }
}
