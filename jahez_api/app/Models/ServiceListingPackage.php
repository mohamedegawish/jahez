<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One package a provider lists for one of its services (ADR-027): a name and, each
 * optional, a monthly price, an annual price (DECIMAL(14,2) EGP strings, informational)
 * and the number of users the price covers; at least one price. Written only through
 * PUT /service-providers/{id}/services/{catalogService}/packages, which replaces the
 * listing's packages and sends the listing back to IMC review; nothing is mass-assigned.
 *
 * @property int $id
 * @property int $catalog_service_id
 * @property int $service_provider_id
 * @property int $position
 * @property string $name_ar
 * @property string|null $monthly_price
 * @property string|null $annual_price
 * @property int|null $users_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ServiceListingPackage extends Model
{
    /**
     * At most this many packages per listing (technical bound, not a business rule).
     */
    public const MAX_PER_LISTING = 10;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'catalog_service_id' => 'integer',
            'service_provider_id' => 'integer',
            'position' => 'integer',
            'monthly_price' => 'decimal:2',
            'annual_price' => 'decimal:2',
            'users_count' => 'integer',
        ];
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
     * The listed price for the period, or null when the package states none for it.
     */
    public function priceFor(BillingPeriod $period): ?string
    {
        return $this->getAttribute($period->priceColumn());
    }

    /**
     * The package as listed now, the shape API resources and request snapshots use.
     *
     * @return array{id: int, name_ar: string, monthly_price: string|null, annual_price: string|null, users_count: int|null}
     */
    public function toListing(): array
    {
        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'monthly_price' => $this->monthly_price,
            'annual_price' => $this->annual_price,
            'users_count' => $this->users_count,
        ];
    }
}
