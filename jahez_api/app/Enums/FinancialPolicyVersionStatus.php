<?php

namespace App\Enums;

/**
 * Status of a financial policy version (ADR-023). Clients never set it: each change is
 * an action. Whether an approved version is scheduled, active or expired follows from
 * its effective dates (FinancialPolicyVersion::effectiveStatus()), so no job has to flip
 * it at midnight.
 */
enum FinancialPolicyVersionStatus: string
{
    /** Being prepared; the only status in which values can change. */
    case Draft = 'draft';

    /** Submitted and waiting for a second administrator's decision. */
    case PendingApproval = 'pending_approval';

    /** Refused by the approver; kept as history. A new draft may follow. */
    case Rejected = 'rejected';

    /** Approved: applies during its effective period. */
    case Approved = 'approved';

    /** Approved, then closed by a successor that starts the day after its new end date. */
    case Superseded = 'superseded';

    /** Approved, then ended early on an approver's decision. */
    case Ended = 'ended';

    /** Discarded before it ever applied (a draft, a rejected version or a withdrawn scheduled one). */
    case Archived = 'archived';

    public function canBecome(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::PendingApproval, self::Archived],
            self::PendingApproval => [self::Approved, self::Rejected],
            self::Rejected => [self::Archived],
            self::Approved => [self::Superseded, self::Ended, self::Archived],
            self::Ended => [self::Superseded],
            default => [],
        }, true);
    }

    /**
     * Whether the version applies during its effective period. Only approved versions
     * ever do; superseded and ended ones still apply to the dates they covered.
     */
    public function isResolvable(): bool
    {
        return in_array($this, self::resolvable(), true);
    }

    /**
     * @return list<self>
     */
    public static function resolvable(): array
    {
        return [self::Approved, self::Superseded, self::Ended];
    }

    /**
     * Whether the version is still being worked on (at most one per policy).
     */
    public function isOpen(): bool
    {
        return $this === self::Draft || $this === self::PendingApproval;
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::PendingApproval => 'بانتظار الاعتماد',
            self::Rejected => 'مرفوضة',
            self::Approved => 'معتمدة',
            self::Superseded => 'مستبدلة بإصدار أحدث',
            self::Ended => 'منتهية',
            self::Archived => 'مؤرشفة',
        };
    }
}
