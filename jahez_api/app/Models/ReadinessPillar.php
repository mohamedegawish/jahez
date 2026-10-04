<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A pillar (محور) of the readiness questionnaire (ADR-018).
 *
 * @property int $id
 * @property int $readiness_questionnaire_id
 * @property string $code
 * @property string $name_ar
 * @property string|null $name_en
 * @property int $sort_order
 */
class ReadinessPillar extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'readiness_questionnaire_id',
        'code',
        'name_ar',
        'name_en',
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
            'readiness_questionnaire_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ReadinessQuestionnaire, $this>
     */
    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(ReadinessQuestionnaire::class, 'readiness_questionnaire_id');
    }

    /**
     * @return HasMany<ReadinessQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(ReadinessQuestion::class)->orderBy('number');
    }
}
