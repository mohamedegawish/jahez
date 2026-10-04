<?php

namespace App\Enums;

/**
 * How an agreement, contract or invoice relates to the managed policies (ADR-023).
 */
enum PolicyBasis: string
{
    /**
     * Made before policies were managed in the database. Its values (if any) came from
     * the earlier environment settings and are kept as they were; it references no
     * policy version and is never recalculated.
     */
    case Legacy = 'legacy';

    /**
     * Made under the managed policies: its version references are authoritative, and a
     * NULL reference means no approved policy applied when it was made.
     */
    case Policy = 'policy';
}
