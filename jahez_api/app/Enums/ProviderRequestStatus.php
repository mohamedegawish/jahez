<?php

namespace App\Enums;

/**
 * Status of a service request as sent to one provider (ADR-015; PROPOSED states,
 * docs/workflows.md, OQ-38). Clients never set it directly: each change is an explicit
 * action whose actor and current status the server checks.
 */
enum ProviderRequestStatus: string
{
    /** Sent; the provider has not answered. */
    case Pending = 'pending';

    /** The provider accepted to discuss: messages and offers are open. */
    case Accepted = 'accepted';

    /** The provider declined, before or during the negotiation. */
    case Declined = 'declined';

    /** The factory withdrew the request from this provider. */
    case Withdrawn = 'withdrawn';

    /** The factory accepted this provider's latest offer. Not a contract (OQ-17). */
    case Agreed = 'agreed';

    /** Closed because the request was cancelled or awarded to another provider. */
    case Closed = 'closed';

    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Pending, self::Accepted], true);
    }

    /**
     * Whether the transition is allowed from this status, whoever asks for it; the
     * policies decide who may ask.
     */
    public function canBecome(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Pending => [self::Accepted, self::Declined, self::Withdrawn, self::Closed],
            self::Accepted => [self::Declined, self::Withdrawn, self::Agreed, self::Closed],
            default => [],
        }, true);
    }
}
