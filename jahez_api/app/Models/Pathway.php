<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A transformation pathway (مسار) from the source document, section 3.
 */
class Pathway extends Model
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
        'target_group_ar',
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
     * @return HasMany<PathwayLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(PathwayLevel::class)->orderBy('sort_order');
    }
}
