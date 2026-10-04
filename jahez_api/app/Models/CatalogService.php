<?php

namespace App\Models;

use App\Enums\ServiceListingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A service listed under a workbook category (ADR-014). Named CatalogService because
 * "service" means something else throughout Laravel.
 *
 * @property int $id
 * @property int $service_category_id
 * @property string $code
 * @property string $name_ar
 * @property string $source_ref
 * @property int $sort_order
 */
class CatalogService extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'service_category_id',
        'code',
        'name_ar',
        'source_ref',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_category_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ServiceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    /**
     * The providers that offer this service.
     *
     * @return BelongsToMany<ServiceProvider, $this>
     */
    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(ServiceProvider::class)->withPivot(['status', 'status_reason', 'status_changed_at', 'submitted_at']);
    }

    /**
     * The providers whose listing of this service IMC approved (ADR-021).
     *
     * @return BelongsToMany<ServiceProvider, $this>
     */
    public function approvedProviders(): BelongsToMany
    {
        return $this->providers()->wherePivot('status', ServiceListingStatus::Approved->value);
    }

    /**
     * The readiness roadmap lines that recommend this service (ADR-018).
     *
     * @return BelongsToMany<ReadinessRecommendation, $this>
     */
    public function readinessRecommendations(): BelongsToMany
    {
        return $this->belongsToMany(ReadinessRecommendation::class);
    }
}
