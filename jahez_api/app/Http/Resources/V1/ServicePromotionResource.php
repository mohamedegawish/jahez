<?php

namespace App\Http\Resources\V1;

use App\Models\ServicePromotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An IMC promotion of a provider listing (ADR-020), for IMC administrators. `state` is
 * active, scheduled, expired or ended.
 *
 * @mixin ServicePromotion
 */
class ServicePromotionResource extends JsonResource
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
            'provider' => $this->whenLoaded('serviceProvider', fn (): ?array => $this->serviceProvider === null ? null : [
                'id' => $this->serviceProvider->id,
                'name' => $this->serviceProvider->name,
                'approval_status' => $this->serviceProvider->approval_status->value,
            ]),
            'service' => new CatalogServiceResource($this->whenLoaded('service')),
            'headline' => $this->headline,
            'priority' => $this->priority,
            'state' => $this->state(),
            'starts_at' => $this->starts_at->toIso8601ZuluString(),
            'ends_at' => $this->ends_at?->toIso8601ZuluString(),
            'ended_at' => $this->ended_at?->toIso8601ZuluString(),
            'created_by' => $this->whenLoaded('createdBy', fn (): ?array => $this->createdBy === null ? null : ['id' => $this->createdBy->id, 'name' => $this->createdBy->name]),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
