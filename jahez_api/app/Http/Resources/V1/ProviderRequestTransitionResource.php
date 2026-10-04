<?php

namespace App\Http\Resources\V1;

use App\Models\ProviderRequestTransition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One status change in a provider request's history. `actor.side` is "factory" or
 * "provider" relative to this thread, or null for anyone else; `actor` is null for a
 * change no user made. Expects actor and providerRequest.serviceRequest to be loaded.
 *
 * @mixin ProviderRequestTransition
 */
class ProviderRequestTransitionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'from' => $this->from_status?->value,
            'to' => $this->to_status->value,
            'reason' => $this->reason,
            'actor' => $this->actor === null ? null : [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'side' => $this->providerRequest?->sideOf($this->actor),
            ],
            'at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
