<?php

namespace App\Billing;

use Carbon\CarbonImmutable;

/**
 * The calendar day a financial policy's effective dates are read in (ADR-023). Effective
 * dates are whole days in the business time zone (config jahez.financial_policies
 * .timezone), not in the server's UTC, so a policy that starts on 1 January applies from
 * midnight in Cairo.
 */
final class PolicyCalendar
{
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    /**
     * The business day a moment falls on.
     */
    public static function dayOf(\DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(self::timezone())->startOfDay();
    }

    public static function parse(string $day): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $day, self::timezone()) ?: throw new \InvalidArgumentException("Not a date: {$day}");
    }

    public static function timezone(): string
    {
        return (string) config('jahez.financial_policies.timezone', 'Africa/Cairo');
    }
}
