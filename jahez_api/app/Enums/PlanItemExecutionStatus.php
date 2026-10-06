<?php

namespace App\Enums;

/**
 * The execution status IMC records for a transformation plan item (ADR-025; owner
 * decision 2026-10-05: IMC records it until the ministry defines who reports execution
 * and on what evidence, OQ-52). It is separate from the status of any service request
 * linked to the item: sending a request never starts or completes an item. Clients never
 * send it; each change is an explicit action.
 */
enum PlanItemExecutionStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Whether the item counts towards the plan's progress: a cancelled item does not.
     */
    public function countsTowardsProgress(): bool
    {
        return $this !== self::Cancelled;
    }

    /**
     * Whether the item has left the not-started state at some point and still matters for
     * the factory's history (it may not silently disappear from a new version).
     */
    public function isUnderway(): bool
    {
        return in_array($this, [self::InProgress, self::OnHold, self::Completed], true);
    }

    /**
     * Whether no further work is expected: completed or cancelled.
     */
    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /**
     * Whether the transition is allowed from this status. A completed item is final
     * (correcting it is part of OQ-52); a cancelled one can be reopened.
     */
    public function canBecome(self $next): bool
    {
        return in_array($next, match ($this) {
            self::NotStarted => [self::InProgress, self::OnHold, self::Cancelled],
            self::InProgress => [self::Completed, self::OnHold, self::Cancelled],
            self::OnHold => [self::NotStarted, self::InProgress, self::Cancelled],
            self::Cancelled => [self::NotStarted],
            self::Completed => [],
        }, true);
    }
}
