<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The choice a factory selected for one question of an assessment (ADR-018), with the
 * points that choice was worth and the text it was given with at submission. Entries are never updated or deleted.
 *
 * @property int $id
 * @property int $readiness_assessment_id
 * @property int $readiness_question_id
 * @property int $readiness_choice_id
 * @property int $points
 * @property string|null $question_text_ar the question text at submission (null only for rows written before the snapshot)
 * @property string|null $choice_label_ar
 * @property string|null $choice_text_ar
 */
class ReadinessAssessmentAnswer extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'readiness_assessment_id' => 'integer',
            'readiness_question_id' => 'integer',
            'readiness_choice_id' => 'integer',
            'points' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Readiness assessment answers are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Readiness assessment answers are append-only.'));
    }

    /**
     * @return BelongsTo<ReadinessAssessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(ReadinessAssessment::class, 'readiness_assessment_id');
    }

    /**
     * @return BelongsTo<ReadinessQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(ReadinessQuestion::class, 'readiness_question_id');
    }

    /**
     * @return BelongsTo<ReadinessChoice, $this>
     */
    public function choice(): BelongsTo
    {
        return $this->belongsTo(ReadinessChoice::class, 'readiness_choice_id');
    }
}
