<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scope-of-work item (نطاق العمل) of a pathway level.
 */
class PathwayScopeItem extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pathway_level_id',
        'group_ar',
        'group_en',
        'text_ar',
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
     * @return BelongsTo<PathwayLevel, $this>
     */
    public function pathwayLevel(): BelongsTo
    {
        return $this->belongsTo(PathwayLevel::class);
    }
}
