<?php

namespace App\Enums;

/**
 * Status of a factory's transformation plan (ADR-025). Clients never send it: it changes
 * only through IMC's publish, suspend, resume and close actions.
 */
enum TransformationPlanStatus: string
{
    /** Created and never published: the factory does not see it. */
    case Draft = 'draft';

    /** A published version is in force and visible to the factory. */
    case Published = 'published';

    /** Paused by IMC: still visible to the factory, but no item may start or be requested. */
    case Suspended = 'suspended';

    /** Ended by IMC: kept read-only; the factory may receive a new plan. */
    case Closed = 'closed';

    /**
     * Whether the factory may see the plan: it has been published at least once.
     */
    public function isVisibleToFactory(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Whether the transition is allowed from this status.
     */
    public function canBecome(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Published],
            self::Published => [self::Suspended, self::Closed],
            self::Suspended => [self::Published, self::Closed],
            self::Closed => [],
        }, true);
    }
}
