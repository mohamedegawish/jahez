<?php

namespace App\Enums;

/**
 * Status of an invoice (ADR-017, ADR-023). Clients never set it: drafting, issuing and
 * cancelling are actions, and `partially_paid`, `paid` and `refunded` follow verified
 * payment evidence or an authorised, audited manual entry only. Overdue is not a stored
 * status: it follows from the due date (Invoice::isOverdue()). An
 * issued invoice cannot be cancelled or voided until credit notes are decided (OQ-16).
 */
enum InvoiceStatus: string
{
    /** Being prepared by the issuer; lines may change; no number. */
    case Draft = 'draft';

    /** Numbered, taxed and totalled; lines are frozen; awaiting payment. */
    case Issued = 'issued';

    /**
     * Verified payments cover part of the total: a manual entry for part of it, when the
     * payment terms allow part payments (ADR-023).
     */
    case PartiallyPaid = 'partially_paid';

    /** Verified payments cover the total: gateway evidence or an authorised manual entry. */
    case Paid = 'paid';

    /** Its payment was refunded, as the payment gateway reported. */
    case Refunded = 'refunded';

    /** A draft the issuer withdrew. */
    case Cancelled = 'cancelled';

    public function canBecome(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Issued, self::Cancelled],
            self::Issued => [self::PartiallyPaid, self::Paid],
            self::PartiallyPaid => [self::Paid],
            self::Paid => [self::Refunded],
            default => [],
        }, true);
    }
}
