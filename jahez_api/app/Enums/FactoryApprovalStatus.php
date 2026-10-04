<?php

namespace App\Enums;

/**
 * IMC review of a factory's account and profile (owner brief "Phase 3", ADR-021). It is
 * separate from the readiness classification: no decision here changes an assessment,
 * its score or its category. The states and transitions mirror ProviderApprovalStatus.
 *
 * What an unapproved factory may not do is PROPOSED (OQ-46): with
 * config jahez.factories.approval_required on, only an approved factory sends service
 * requests. Its profile, documents and readiness assessment stay open to it throughout.
 */
enum FactoryApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case ChangesRequested = 'changes_requested';

    /**
     * Whether an IMC administrator may move a factory from this status to the given one.
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
     * Whether the factory itself may ask IMC to review it again: after a rejection or a
     * request for corrections, which sends it back to pending.
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
