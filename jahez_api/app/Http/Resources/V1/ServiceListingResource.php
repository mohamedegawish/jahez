<?php

namespace App\Http\Resources\V1;

use App\Models\CatalogService;
use App\Models\ServiceListingPackage;
use App\Models\ServicePromotion;
use App\Models\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * A provider's listing of a catalog service (ADR-020). The catalog part (code, name,
 * category) is IMC's fixed catalog (ADR-014); the provider part is the provider's own
 * profile; `promotion` is IMC's placement, labelled «إعلان». The provider's logo is read
 * with the bearer token from `provider.logo_path` (relative to /api/v1).
 *
 * The approval status is shown to the provider itself and to IMC; factories only ever
 * see approved providers. `packages` are the provider's packages and prices for the
 * service (ADR-027): EGP, informational, reviewed by IMC with the listing.
 *
 * @property array{provider: ServiceProvider|null, service: CatalogService|null, review: array{status: string, reason: string|null, changed_at: string|null, submitted_at: string|null}, promotion: ServicePromotion|null, logo_document_id: int|null, packages: list<ServiceListingPackage>, recommended: bool|null, viewer: string} $resource
 */
class ServiceListingResource extends JsonResource
{
    public const PROMOTION_LABEL = 'إعلان';

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $provider = $this->resource['provider'];
        $service = $this->resource['service'];
        $promotion = $this->resource['promotion'];
        $logoId = $this->resource['logo_document_id'];
        $viewer = $this->resource['viewer'];

        return [
            'id' => ($provider->id ?? 0).'-'.($service->id ?? 0),
            'provider' => $provider === null ? null : [
                'id' => $provider->id,
                'name' => $provider->name,
                'description' => $provider->description,
                'governorate' => $provider->governorate,
                'dx_experience_years' => $provider->dx_experience_years,
                'has_logo' => $logoId !== null,
                'logo_path' => $logoId === null ? null : ($viewer === 'factory'
                    ? "/provider-directory/{$provider->id}/logo"
                    : "/service-providers/{$provider->id}/documents/{$logoId}"),
                ...($viewer !== 'factory' ? ['approval_status' => $provider->approval_status->value] : []),
            ],
            'service' => $service === null ? null : [
                'id' => $service->id,
                'code' => $service->code,
                'name_ar' => $service->name_ar,
                'category' => $service->category === null ? null : [
                    'code' => $service->category->code,
                    'name_ar' => $service->category->name_ar,
                ],
            ],
            'promotion' => $promotion === null ? null : [
                'id' => $promotion->id,
                'label' => self::PROMOTION_LABEL,
                'headline' => $promotion->headline,
                'ends_at' => $promotion->ends_at?->toIso8601ZuluString(),
            ],
            'packages' => array_map(fn (ServiceListingPackage $package): array => $package->toListing(), $this->resource['packages']),
            'recommended' => $this->resource['recommended'],
            // IMC's review of the listing (ADR-021). A factory only ever sees approved ones.
            ...($viewer !== 'factory' ? ['review' => [
                'status' => $this->resource['review']['status'],
                'reason' => $this->resource['review']['reason'],
                'changed_at' => self::zulu($this->resource['review']['changed_at']),
                'submitted_at' => self::zulu($this->resource['review']['submitted_at']),
            ]] : []),
        ];
    }

    /**
     * A database timestamp (UTC) as ISO 8601.
     */
    private static function zulu(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value, 'UTC')->toIso8601ZuluString();
    }
}
