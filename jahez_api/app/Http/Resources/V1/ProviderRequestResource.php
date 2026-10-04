<?php

namespace App\Http\Resources\V1;

use App\Models\ProviderRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * A service request as sent to one provider (ADR-015): its status, and the shared
 * request when the caller loaded it. Messages and offers have their own endpoints.
 * An `agreed` status is not a contract (OQ-17); `agreement` shows the agreement's IMC
 * review and contract-draft statuses (ADR-020), never its terms.
 *
 * Activity fields, when the controller added them: `unread_messages_count` (the other
 * side's messages the caller has not read; parties only), `last_message_at` and
 * `latest_offer_version`.
 *
 * @mixin ProviderRequest
 */
class ProviderRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();
        $lastMessageAt = $attributes['last_message_at'] ?? null;
        $latestOfferVersion = $attributes['latest_offer_version'] ?? null;

        return [
            'id' => $this->id,
            'provider' => $this->whenLoaded('serviceProvider', fn (): ?array => $this->serviceProvider === null ? null : [
                'id' => $this->serviceProvider->id,
                'name' => $this->serviceProvider->name,
            ]),
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'status_changed_at' => $this->status_changed_at?->toIso8601ZuluString(),
            'agreed_offer_id' => $this->agreed_offer_id,
            'agreement_id' => $this->whenLoaded('agreement', fn (): ?int => $this->agreement?->id),
            'agreement' => $this->whenLoaded('agreement', fn (): ?array => $this->agreement === null ? null : [
                'id' => $this->agreement->id,
                'review_status' => $this->agreement->relationLoaded('review') ? $this->agreement->reviewStatus()->value : null,
                'contract_status' => $this->agreement->relationLoaded('activeContract') ? $this->agreement->activeContract?->status->value : null,
            ]),
            'unread_messages_count' => $this->when(
                array_key_exists('unread_messages_count', $attributes),
                fn (): int => (int) $attributes['unread_messages_count'],
            ),
            'last_message_at' => $this->when(
                array_key_exists('last_message_at', $attributes),
                fn (): ?string => $lastMessageAt === null ? null : Carbon::parse((string) $lastMessageAt)->toIso8601ZuluString(),
            ),
            'latest_offer_version' => $this->when(
                array_key_exists('latest_offer_version', $attributes),
                fn (): ?int => $latestOfferVersion === null ? null : (int) $latestOfferVersion,
            ),
            'service_request' => new ServiceRequestResource($this->whenLoaded('serviceRequest')),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
