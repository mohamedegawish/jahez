<?php

namespace App\Enums;

/**
 * The period a listed package price covers (ADR-027): a provider may give a monthly price,
 * an annual price or both for each package, and a factory chooses one when it puts the
 * package in its cart. Informational only: no payment is made or scheduled (OQ-15, OQ-16).
 */
enum BillingPeriod: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';

    /**
     * The package column holding the price for this period.
     */
    public function priceColumn(): string
    {
        return match ($this) {
            self::Monthly => 'monthly_price',
            self::Annual => 'annual_price',
        };
    }
}
