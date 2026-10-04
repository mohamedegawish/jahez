<?php

namespace App\Http\Resources\V1;

use App\Models\FactoryProfileChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A request to change a factory's recorded legal information (ADR-020), for the
 * factory's members and IMC administrators.
 *
 * @mixin FactoryProfileChangeRequest
 */
class FactoryChangeRequestResource extends JsonResource
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
            'factory_id' => $this->factory_id,
            // The values on the factory now, so a reviewer can compare them with `changes`.
            'factory' => $this->whenLoaded('industrialFactory', fn (): ?array => $this->industrialFactory === null ? null : [
                'id' => $this->industrialFactory->id,
                'name' => $this->industrialFactory->name,
                'legal_name' => $this->industrialFactory->legal_name,
                'commercial_registration_number' => $this->industrialFactory->commercial_registration_number,
                'tax_registration_number' => $this->industrialFactory->tax_registration_number,
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
