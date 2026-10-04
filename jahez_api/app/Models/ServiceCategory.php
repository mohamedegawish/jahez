<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the seven service categories of the services workbook (ADR-014).
 *
 * @property int $id
 * @property string $code
 * @property string $name_ar
 * @property string|null $name_en
 * @property string $source_ref
 * @property int $sort_order
 */
class ServiceCategory extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
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
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<CatalogService, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(CatalogService::class)->orderBy('sort_order');
    }
}
