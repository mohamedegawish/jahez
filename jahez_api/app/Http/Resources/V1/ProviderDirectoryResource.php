<?php

namespace App\Http\Resources\V1;

use App\Models\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public profile a factory sees in the provider directory (ADR-014): company name,
 * website, experience, sectors and services. The contact person's name, job title,
 * email and phone are shown only when config jahez.providers.directory_shows_contact_details
 * is on (OQ-37; off until the owner decides). The approval state is never shown.
 *
 * @mixin ServiceProvider
 */
class ProviderDirectoryResource extends JsonResource
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
            'description' => $this->description,
            'governorate' => $this->governorate,
            'city' => $this->city,
            'website' => $this->website,
            // The logo is read from /provider-directory/{id}/logo (ADR-019).
            'has_logo' => (bool) ($this->has_logo ?? false),
            'dx_experience_years' => $this->dx_experience_years,
            ...((bool) config('jahez.providers.directory_shows_contact_details') ? [
                'representative_name' => $this->representative_name,
                'job_title' => $this->job_title,
                'email' => $this->email,
                'phone' => $this->phone,
            ] : []),
            'sectors' => SectorResource::collection($this->whenLoaded('sectors')),
            // Only the listings IMC approved (ADR-021).
            'services' => CatalogServiceResource::collection($this->whenLoaded('approvedServices')),
        ];
    }
}
