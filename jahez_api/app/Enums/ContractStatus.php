<?php

namespace App\Enums;

/**
 * Status of a contract draft (ADR-017). Under the OQ-17 interim every contract is a
 * draft and not legally binding; signature, approval and execution states are not
 * modelled until the owner decides the parties, templates and e-signature.
 */
enum ContractStatus: string
{
    /** Drafted from an agreement by one of its parties. Not binding. */
    case Draft = 'draft';

    /** Withdrawn by a party; a new draft may follow as the next version. */
    case Cancelled = 'cancelled';

    public function canBecome(self $next): bool
    {
        return $this === self::Draft && $next === self::Cancelled;
    }
}
