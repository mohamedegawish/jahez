<?php

namespace App\Models;

use App\Enums\ReadinessCategoryCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A catalog service IMC made available to factories of one digital readiness level
 * (ADR-025). Inactive rows keep the decision without making the service available.
 * Changed only through PUT/DELETE /readiness-levels/{level}/services/{catalogService};
 * nothing is mass-assigned.
 *
 * @property int $id
 * @property ReadinessCategoryCode $level
 * @property int $catalog_service_id
 * @property bool $is_active
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CatalogService $service
 */
class ReadinessLevelService extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => ReadinessCategoryCode::class,
            'catalog_service_id' => 'integer',
            'is_active' => 'boolean',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CatalogService, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(CatalogService::class, 'catalog_service_id');
    }
}
