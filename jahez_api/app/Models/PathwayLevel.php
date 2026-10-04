<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A level within a pathway: the Foundational Pathway, or Basic / Advanced / Smart DX.
 */
class PathwayLevel extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pathway_id',
        'code',
        'name_ar',
        'subtitle_ar',
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
     * @return BelongsTo<Pathway, $this>
     */
    public function pathway(): BelongsTo
    {
        return $this->belongsTo(Pathway::class);
    }

    /**
     * @return HasMany<PathwayScopeItem, $this>
     */
    public function scopeItems(): HasMany
    {
        return $this->hasMany(PathwayScopeItem::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<LevelProviderRequirement, $this>
     */
    public function providerRequirements(): HasMany
    {
        return $this->hasMany(LevelProviderRequirement::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<MaturityTier, $this>
     */
    public function maturityTiers(): HasMany
    {
        return $this->hasMany(MaturityTier::class);
    }
}
