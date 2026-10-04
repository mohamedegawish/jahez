<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A technical requirement for service providers at a DX level
 * (الاشتراطات الفنية الخاصة بمقدم الخدمة). Whether it is an eligibility gate or an
 * evaluation input is still open (docs/open-questions.md OQ-14).
 */
class LevelProviderRequirement extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pathway_level_id',
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
