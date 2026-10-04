<?php

namespace App\Billing;

/**
 * What a gateway returns when it starts a payment: its own reference, and the page the
 * payer completes the payment on, if any.
 */
final readonly class GatewayCheckout
{
    public function __construct(
        public string $reference,
        public ?string $checkoutUrl = null,
    ) {}
}
