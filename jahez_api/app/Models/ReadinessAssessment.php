<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

/**
 * A completed digital readiness assessment of a factory (ADR-018). The server sums the
 * points of the selected choices and classifies the total with the ranges of the
 * questionnaire version answered. The total, the category and each answer's points are
 * kept as they were at submission, so a result is never recalculated. Entries are never
 * updated or deleted; a new assessment is a new entry.
 *
 * @property int $id
 * @property int $factory_id
 * @property int $readiness_questionnaire_id
 * @property int $readiness_category_id
 * @property int $total_score
 * @property int $submitted_by_user_id
 * @property string|null $idempotency_key
 * @property Carbon $completed_at
 */
class ReadinessAssessment extends Model
{
    /**
     * An assessment is created complete, so its creation time is its completion time.
     */
    public const CREATED_AT = 'completed_at';

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'factory_id' => 'integer',
            'readiness_questionnaire_id' => 'integer',
            'readiness_category_id' => 'integer',
            'total_score' => 'integer',
            'submitted_by_user_id' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $assessment): void {
            $questionnaire = ReadinessQuestionnaire::query()->findOrFail($assessment->readiness_questionnaire_id);

            try {
                $category = $questionnaire->categoryForScore($assessment->total_score);
            } catch (InvalidArgumentException $exception) {
                throw new LogicException($exception->getMessage(), previous: $exception);
            }

            if ($category->id !== $assessment->readiness_category_id) {
                throw new LogicException("A total score of {$assessment->total_score} belongs to the {$category->code->value} category.");
            }
        });
        static::updating(fn (): never => throw new LogicException('Readiness assessments are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Readiness assessments are append-only.'));
    }

    /**
     * Named like User::industrialFactory(): factory() is reserved for model factories.
     *
     * @return BelongsTo<Factory, $this>
     */
    public function industrialFactory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }

    /**
     * @return BelongsTo<ReadinessQuestionnaire, $this>
     */
    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(ReadinessQuestionnaire::class, 'readiness_questionnaire_id');
    }

    /**
     * @return BelongsTo<ReadinessCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ReadinessCategory::class, 'readiness_category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * @return HasMany<ReadinessAssessmentAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(ReadinessAssessmentAnswer::class);
    }
}
