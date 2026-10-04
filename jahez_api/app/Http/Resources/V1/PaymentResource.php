<?php

namespace App\Http\Resources\V1;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment of an invoice (ADR-017). `status` changes only on verified gateway evidence;
 * `checkout_url` is where the payer completes the payment, if the gateway gave one.
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'method' => $this->method,
            'gateway' => $this->gateway,
            'gateway_reference' => $this->gateway_reference,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'checkout_url' => $this->checkout_url,
            'failure_reason' => $this->failure_reason,
            'received_on' => $this->received_on?->toDateString(),
            'evidence_note' => $this->evidence_note,
            'recorded_by_user_id' => $this->recorded_by_user_id,
            'status_changed_at' => $this->status_changed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
