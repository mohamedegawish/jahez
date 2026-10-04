<?php

namespace App\Billing;

use App\Enums\PaymentStatus;

/**
 * A verified, normalised observation from a gateway: a callback or a status query.
 * `amount` is a decimal string with two decimals, never a float.
 */
final readonly class GatewayEvent
{
    public function __construct(
        public string $eventId,
        public string $reference,
        public PaymentStatus $status,
        public string $amount,
        public string $currency,
        public ?string $failureReason = null,
    ) {}
}
