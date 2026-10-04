<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A service line the source roadmap recommends for a readiness category (ADR-018),
 * with the existing catalog services it corresponds to. A line the catalog does not
 * offer has no services (OQ-42). A recommendation never makes a provider eligible.
 *
 * @property int $id
 * @property int $readiness_category_id
 * @property int $sort_order
 * @property string $text_ar
 * @property string $source_ref
 */
class ReadinessRecommendation extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'readiness_category_id',
        'sort_order',
        'text_ar',
        'source_ref',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'readiness_category_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ReadinessCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ReadinessCategory::class, 'readiness_category_id');
    }

    /**
     * @return BelongsToMany<CatalogService, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(CatalogService::class)->orderBy('service_category_id')->orderBy('sort_order');
    }
}
