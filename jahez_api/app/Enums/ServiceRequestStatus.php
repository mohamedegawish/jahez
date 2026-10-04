<?php

namespace App\Enums;

/**
 * Status of a factory's service request (ADR-015; PROPOSED states, docs/workflows.md,
 * OQ-38). Clients never set it directly: it changes only through the cancel and
 * accept-offer actions.
 */
enum ServiceRequestStatus: string
{
    /** Sent to its providers; negotiations may run. */
    case Open = 'open';

    /** The factory accepted one provider's offer. */
    case Awarded = 'awarded';

    /** The factory cancelled the request. */
    case Cancelled = 'cancelled';

    /**
     * Whether the transition is allowed from this status. Awarded and cancelled requests
     * are final.
     */
    public function canBecome(self $next): bool
    {
        return $this === self::Open && in_array($next, [self::Awarded, self::Cancelled], true);
    }
}
