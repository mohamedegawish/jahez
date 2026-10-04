<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client maturity tier from the Client Assessment & Tiers Matrix. Numeric score
 * thresholds remain null until approved (docs/open-questions.md OQ-07).
 */
class MaturityTier extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pathway_level_id',
        'code',
        'name_ar',
        'name_en',
        'readiness_band_ar',
        'operational_state_ar',
        'approved_path_ar',
        'expected_impact_ar',
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
            'score_min' => 'decimal:2',
            'score_max' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The approved execution path (المسار التنفيذي المعتمد) for this tier.
     *
     * @return BelongsTo<PathwayLevel, $this>
     */
    public function pathwayLevel(): BelongsTo
    {
        return $this->belongsTo(PathwayLevel::class);
    }
}
