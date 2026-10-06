<?php

namespace App\Enums;

/**
 * Where the service request linked to a plan item stands in the existing marketplace
 * workflow (ADR-015, ADR-017, ADR-020), as the plan shows it (ADR-025). Read from the
 * request, its provider threads, the agreement and IMC's review; never stored.
 */
enum PlanRequestProgress: string
{
    /** Sent; no provider has accepted yet. */
    case Open = 'open';

    /** A provider accepted: messages and offers are open. */
    case Negotiating = 'negotiating';

    /** Still open, but every provider declined or was withdrawn. */
    case NoActiveProvider = 'no_active_provider';

    /** An offer was accepted; the agreement waits for IMC review. */
    case AwaitingImcReview = 'awaiting_imc_review';

    /** An offer was accepted and IMC approval is not required (configuration). */
    case Agreed = 'agreed';

    /** IMC approved the agreement. */
    case ImcApproved = 'imc_approved';

    /** IMC rejected the agreement (final, OQ-43): a new request may be sent for the item. */
    case ImcRejected = 'imc_rejected';

    /** The factory cancelled the request. */
    case Cancelled = 'cancelled';

    /**
     * Whether the request still occupies the plan item, so a second one is refused.
     */
    public function blocksNewRequest(): bool
    {
        return ! in_array($this, [self::Cancelled, self::ImcRejected], true);
    }

    /**
     * Whether the agreement allows IMC to record the start of the item.
     */
    public function allowsStart(): bool
    {
        return in_array($this, [self::ImcApproved, self::Agreed], true);
    }
}
