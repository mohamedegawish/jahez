<?php

namespace App\Enums;

/**
 * A provider's request to change verified legal information (ADR-019). Only a pending
 * request can be decided or cancelled; every other state is final.
 */
enum ProfileChangeRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
