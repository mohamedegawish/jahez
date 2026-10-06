<?php

namespace App\Http\Resources\V1;

use App\Models\ServiceCartItem;
use App\Models\ServiceListingPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An item of the signed-in member's cart (ADR-027): the service, the provider, the chosen
 * package (as listed now), period and users, the listed price for that choice, the
 * listing's other packages to choose from, and whether the provider can still be sent the
 * request (`available`, decided by ServiceEligibility when the cart is read). Prices are
 * EGP and informational.
 *
 * Expects service.category, serviceProvider and package to be loaded; the controller sets
 * the availability and the listing's packages.
 *
 * @mixin ServiceCartItem
 */
class ServiceCartItemResource extends JsonResource
{
    private bool $available = false;

    private ?string $unavailableReason = null;

    /**
     * @var list<ServiceListingPackage>
     */
    private array $listingPackages = [];

    /**
     * @param  list<ServiceListingPackage>  $listingPackages
     */
    public function withContext(bool $available, ?string $unavailableReason, array $listingPackages): self
    {
        $this->available = $available;
        $this->unavailableReason = $unavailableReason;
        $this->listingPackages = $listingPackages;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service' => [
                'id' => $this->service->id,
                'code' => $this->service->code,
                'name_ar' => $this->service->name_ar,
                'category' => $this->service->category === null ? null : [
                    'code' => $this->service->category->code,
                    'name_ar' => $this->service->category->name_ar,
                ],
            ],
            'provider' => [
                'id' => $this->serviceProvider->id,
                'name' => $this->serviceProvider->name,
            ],
            'package' => $this->package?->toListing(),
            'billing_period' => $this->billing_period?->value,
            'users_count' => $this->users_count,
            'price' => $this->price(),
            'packages' => array_map(fn (ServiceListingPackage $package): array => $package->toListing(), $this->listingPackages),
            'available' => $this->available,
            'unavailable_reason' => $this->unavailableReason,
            'added_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
