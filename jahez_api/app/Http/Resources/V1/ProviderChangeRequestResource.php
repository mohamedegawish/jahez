<?php

namespace App\Http\Resources\V1;

use App\Models\ProviderProfileChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A request to change verified legal information (ADR-019), for the provider's members
 * and IMC administrators.
 *
 * @mixin ProviderProfileChangeRequest
 */
class ProviderChangeRequestResource extends JsonResource
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
            'service_provider_id' => $this->service_provider_id,
            // The values on the provider now, so a reviewer can compare them with `changes`.
            'service_provider' => $this->whenLoaded('serviceProvider', fn (): ?array => $this->serviceProvider === null ? null : [
                'id' => $this->serviceProvider->id,
                'name' => $this->serviceProvider->name,
                'legal_name' => $this->serviceProvider->legal_name,
                'commercial_registration_number' => $this->serviceProvider->commercial_registration_number,
                'tax_registration_number' => $this->serviceProvider->tax_registration_number,
            ]),
            'status' => $this->status->value,
            'changes' => (object) ($this->changes ?? []),
            'documents' => OrganizationDocumentResource::collection($this->whenLoaded('documents')),
            'note' => $this->note,
            'requested_by' => $this->whenLoaded('requestedBy', fn (): ?array => $this->requestedBy === null ? null : ['id' => $this->requestedBy->id, 'name' => $this->requestedBy->name]),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn (): ?array => $this->reviewedBy === null ? null : ['id' => $this->reviewedBy->id, 'name' => $this->reviewedBy->name]),
            'reviewed_at' => $this->reviewed_at?->toIso8601ZuluString(),
            'review_reason' => $this->review_reason,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
