<?php

namespace App\Enums;

/**
 * Normalised status of a payment (ADR-017). Each gateway adapter maps its own statuses
 * onto these. Only verified gateway evidence changes a payment; the API never marks one
 * successful by itself.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function canBecome(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Pending => [self::Succeeded, self::Failed, self::Cancelled],
            self::Succeeded => [self::Refunded],
            default => [],
        }, true);
    }
}
