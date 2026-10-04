<?php

namespace App\Enums;

/**
 * IMC approval of a service provider (owner decision 2026-10-03, ADR-014). Only approved
 * providers are visible to factories. The decision is manual: no evaluation score is
 * computed until the evaluation scale and pass mark are approved (OQ-13).
 *
 * `changes_requested` (owner brief "Phase 3", ADR-021): IMC asks the provider to correct
 * its profile before deciding. Like a rejection it keeps the provider hidden from
 * factories, and the provider sends it back to review once it has corrected the profile.
 */
enum ProviderApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case ChangesRequested = 'changes_requested';

    /**
     * Whether an IMC administrator may move a provider from this status to the given one.
     */
    public function canBecome(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::ChangesRequested],
            self::ChangesRequested => [self::Approved, self::Rejected],
            self::Approved => [self::Suspended],
            self::Rejected, self::Suspended => [self::Approved],
        }, true);
    }

    /**
     * Whether the provider itself may ask IMC to review it again (PROPOSED): after a
     * rejection or a request for corrections, which sends it back to pending.
     */
    public function canRequestReview(): bool
    {
        return $this === self::Rejected || $this === self::ChangesRequested;
    }

    /**
     * Whether the decision must be explained.
     */
    public function requiresReason(): bool
    {
        return $this === self::Rejected || $this === self::Suspended || $this === self::ChangesRequested;
    }
}
