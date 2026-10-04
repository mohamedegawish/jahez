<?php

namespace App\Http\Resources\V1;

use App\Models\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A factory's service request (ADR-015). `provider_requests` lists the provider threads
 * the controller loaded: all of them for the factory and IMC, only its own for a
 * provider, so a provider never learns which competitors received the request.
 * `active_provider_count` counts those threads still pending or negotiating: 0 on an
 * open request means every provider has declined or been withdrawn, so the factory can
 * add providers or cancel (PROPOSED, OQ-38).
 *
 * @mixin ServiceRequest
 */
class ServiceRequestResource extends JsonResource
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
            'factory' => $this->whenLoaded('industrialFactory', fn (): ?array => $this->industrialFactory === null ? null : [
                'id' => $this->industrialFactory->id,
                'name' => $this->industrialFactory->name,
            ]),
            'service' => new CatalogServiceResource($this->whenLoaded('service')),
            'title' => $this->title,
            'need' => $this->need,
            'requirements' => $this->requirements,
            'status' => $this->status->value,
            'status_changed_at' => $this->status_changed_at?->toIso8601ZuluString(),
            'provider_requests' => ProviderRequestResource::collection($this->whenLoaded('providerRequests')),
            'active_provider_count' => $this->whenLoaded('providerRequests', fn (): int => $this->providerRequests->filter(fn ($thread): bool => ! $thread->status->isTerminal())->count()),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
