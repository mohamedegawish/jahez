<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A readiness question (ADR-018): the source text, its pillar and its number.
 *
 * @property int $id
 * @property int $readiness_questionnaire_id
 * @property int $readiness_pillar_id
 * @property string $code
 * @property int $number
 * @property string $text_ar
 * @property string $source_ref
 * @property int|null $choices_count
 */
class ReadinessQuestion extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'readiness_questionnaire_id',
        'readiness_pillar_id',
        'code',
        'number',
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
            'readiness_questionnaire_id' => 'integer',
            'readiness_pillar_id' => 'integer',
            'number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ReadinessPillar, $this>
     */
    public function pillar(): BelongsTo
    {
        return $this->belongsTo(ReadinessPillar::class, 'readiness_pillar_id');
    }

    /**
     * @return HasMany<ReadinessChoice, $this>
     */
    public function choices(): HasMany
    {
        return $this->hasMany(ReadinessChoice::class)->orderBy('sort_order');
    }
}
