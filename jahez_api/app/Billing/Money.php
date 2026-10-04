<?php

namespace App\Billing;

use InvalidArgumentException;

/**
 * Exact money arithmetic on integer minor units (1 EGP = 100 piastres). Amounts are
 * DECIMAL(14,2) in the database and decimal strings in the API; they are never floats.
 * Every amount here is non-negative.
 */
final class Money
{
    /**
     * The largest amount DECIMAL(14,2) can hold, in minor units (999,999,999,999.99).
     */
    public const MAX_MINOR = 99_999_999_999_999;

    /**
     * "1234.5" or "1234.50" as 123450.
     */
    public static function toMinor(string $amount): int
    {
        if (preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/', $amount, $parts) !== 1) {
            throw new InvalidArgumentException("Not a non-negative amount with at most two decimals: {$amount}");
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    /**
     * 123450 as "1234.50".
     */
    public static function fromMinor(int $minor): string
    {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }

    /**
     * The given basis points (1% = 100) of an amount, rounded half up to the minor unit.
     */
    public static function percentOf(int $minor, int $basisPoints): int
    {
        return intdiv(2 * $minor * $basisPoints + 10_000, 20_000);
    }

    /**
     * A percentage from 0 to 100 with at most two decimals ("14", "12.5") in basis
     * points, or null when the value is not one.
     */
    public static function basisPoints(mixed $percent): ?int
    {
        if (! is_scalar($percent) || preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', (string) $percent, $parts) !== 1) {
            return null;
        }

        $basisPoints = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');

        return $basisPoints <= 10_000 ? $basisPoints : null;
    }
}
