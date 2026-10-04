<?php

namespace App\Enums;

/**
 * IMC review of one service a provider lists (owner brief "Phase 3", ADR-021). A listing
 * reaches factories only when it is approved and its provider is approved and eligible:
 * neither approval replaces the other, and a promotion never bypasses either.
 */
enum ServiceListingStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    /**
     * Whether an IMC administrator may move a listing from this status to the given one.
     */
    public function canBecome(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Pending => [self::Approved, self::Rejected],
            self::Approved => [self::Suspended],
            self::Rejected, self::Suspended => [self::Approved],
        }, true);
    }

    /**
     * Whether the provider may send the listing back to review (ADR-022): only after a
     * rejection. A suspension is IMC's to lift; a pending listing is already in review.
     */
    public function canBeResubmitted(): bool
    {
        return $this === self::Rejected;
    }

    /**
     * Whether the decision must be explained.
     */
    public function requiresReason(): bool
    {
        return $this === self::Rejected || $this === self::Suspended;
    }
}
