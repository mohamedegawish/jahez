<?php

namespace App\Models;

use App\Enums\ReadinessCategoryCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A digital readiness category of a questionnaire version (ADR-018): its total-score
 * range, the source description, and the source roadmap (focus, steps and the service
 * lines it recommends).
 *
 * @property int $id
 * @property int $readiness_questionnaire_id
 * @property ReadinessCategoryCode $code
 * @property string $name_en
 * @property string $name_ar
 * @property string $description_ar
 * @property int $min_score
 * @property int $max_score
 * @property string $focus_ar
 * @property string $steps_ar
 * @property int $sort_order
 */
class ReadinessCategory extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'readiness_questionnaire_id',
        'code',
        'name_en',
        'name_ar',
        'description_ar',
        'min_score',
        'max_score',
        'focus_ar',
        'steps_ar',
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
            'code' => ReadinessCategoryCode::class,
            'min_score' => 'integer',
            'max_score' => 'integer',
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
     * @return HasMany<ReadinessRecommendation, $this>
     */
    public function recommendations(): HasMany
    {
        return $this->hasMany(ReadinessRecommendation::class)->orderBy('sort_order');
    }
}
