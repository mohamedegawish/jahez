<?php

namespace App\Http\Resources\V1;

use App\Models\CatalogService;
use App\Models\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * The full provider profile, for IMC administrators and the provider's own members:
 * the workbook fields, the registration details (ADR-019), sectors, offered services,
 * the current documents, the open change request and the IMC approval state.
 *
 * @mixin ServiceProvider
 */
class ServiceProviderResource extends JsonResource
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
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'description' => $this->description,
            'representative_name' => $this->representative_name,
            'job_title' => $this->job_title,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'dx_experience_years' => $this->dx_experience_years,
            'governorate' => $this->governorate,
            'city' => $this->city,
            'address' => $this->address,
            'commercial_registration_number' => $this->commercial_registration_number,
            'tax_registration_number' => $this->tax_registration_number,
            'sectors' => SectorResource::collection($this->whenLoaded('sectors')),
            'services' => CatalogServiceResource::collection($this->whenLoaded('services')),
            // IMC's review of each listed service (ADR-021): only approved listings reach factories.
            'service_listings' => $this->whenLoaded('services', fn (): array => $this->services->map(function (CatalogService $service): array {
                /** @var Pivot $pivot */
                $pivot = $service->getRelation('pivot');
                $time = fn (string $key): ?string => $pivot->getAttribute($key) === null ? null : Carbon::parse($pivot->getAttribute($key), 'UTC')->toIso8601ZuluString();

                return [
                    'service' => [
                        'id' => $service->id,
                        'code' => $service->code,
                        'name_ar' => $service->name_ar,
                        'category' => $service->relationLoaded('category') && $service->category !== null
                            ? ['code' => $service->category->code, 'name_ar' => $service->category->name_ar]
                            : null,
                    ],
                    'status' => $pivot->getAttribute('status'),
                    'reason' => $pivot->getAttribute('status_reason'),
                    'changed_at' => $time('status_changed_at'),
                    'submitted_at' => $time('submitted_at'),
                ];
            })->values()->all()),
            'documents' => $this->whenLoaded('activeDocuments', fn (): array => OrganizationDocumentResource::byType($this->activeDocuments)),
            // Whether IMC has verified the legal fields: members then change them only
            // through a change request.
            'legal_information_verified' => $this->hasVerifiedLegalInformation(),
            'open_change_request' => $this->whenLoaded('openChangeRequest', fn (): ?ProviderChangeRequestResource => $this->openChangeRequest === null ? null : new ProviderChangeRequestResource($this->openChangeRequest)),
            // The configured required fields (OQ-36) still empty: approval is refused until
            // they are filled. Only on the single-record response, to keep lists cheap.
            'missing_required_fields' => $this->when($request->routeIs('api.v1.service-providers.show'), fn (): array => $this->missingRequiredProfileFields()),
            'approval' => [
                'status' => $this->approval_status->value,
                'reason' => $this->approval_reason,
                'changed_at' => $this->approval_changed_at?->toIso8601ZuluString(),
            ],
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
