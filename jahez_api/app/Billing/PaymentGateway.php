<?php

namespace App\Billing;

use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * The boundary to a payment gateway (ADR-017). No adapter ships with the application:
 * the gateway is not chosen (OQ-16). An adapter registered in config
 * jahez.billing.gateways implements this contract and maps the gateway's own statuses
 * onto App\Enums\PaymentStatus.
 */
interface PaymentGateway
{
    /**
     * Ask the gateway to start collecting the payment's amount, and return its reference
     * and where to send the payer. It must never report success: only a verified callback
     * or a status query does. Throw on any gateway error.
     */
    public function initiate(Payment $payment): GatewayCheckout;

    /**
     * Verify that a callback really comes from the gateway (signature, timestamp) and
     * normalise it. Throw InvalidGatewayCallbackException when it is not authentic.
     */
    public function parseCallback(Request $request): GatewayEvent;

    /**
     * Ask the gateway for the payment's current status, for reconciliation. The event id
     * must identify this observation, so repeating an unchanged status is a duplicate.
     */
    public function fetchStatus(Payment $payment): GatewayEvent;
}
