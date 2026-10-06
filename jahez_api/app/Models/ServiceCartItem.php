<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A provider listing in a factory member's cart (ADR-027), with the package, billing
 * period and number of users the member chose. The cart belongs to the member; checking
 * it out sends one service request per service. Nothing is mass-assigned: the owner comes
 * from the signed-in account.
 *
 * @property int $id
 * @property int $user_id
 * @property int $catalog_service_id
 * @property int $service_provider_id
 * @property int|null $service_listing_package_id
 * @property BillingPeriod|null $billing_period
 * @property int|null $users_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CatalogService $service
 * @property-read ServiceProvider $serviceProvider
 * @property-read ServiceListingPackage|null $package
 *
 * @phpstan-type CartSelection array{package: array{id: int, name_ar: string, monthly_price: string|null, annual_price: string|null, users_count: int|null}|null, billing_period: string|null, users_count: int|null}
 */
class ServiceCartItem extends Model
{
    /**
     * At most this many items per cart (technical bound, not a business rule).
     */
    public const MAX_ITEMS = 50;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'catalog_service_id' => 'integer',
            'service_provider_id' => 'integer',
            'service_listing_package_id' => 'integer',
            'billing_period' => BillingPeriod::class,
            'users_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CatalogService, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(CatalogService::class, 'catalog_service_id');
    }

    /**
     * @return BelongsTo<ServiceProvider, $this>
     */
    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class);
    }

    /**
     * @return BelongsTo<ServiceListingPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ServiceListingPackage::class, 'service_listing_package_id');
    }

    /**
     * The listed price of the chosen package for the chosen period, or null.
     */
    public function price(): ?string
    {
        return $this->billing_period === null ? null : $this->package?->priceFor($this->billing_period);
    }

    /**
     * What a request sent from this item records on the provider's thread: a copy of the
     * package as listed now, the period and the number of users.
     *
     * @return CartSelection
     */
    public function selection(): array
    {
        return [
            'package' => $this->package?->toListing(),
            'billing_period' => $this->billing_period?->value,
            'users_count' => $this->users_count,
        ];
    }
}
