<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The written assessment of one DOC §6 criterion in a provider evaluation, with a score
 * only when a scale was approved at the time (OQ-13). Append-only, like its evaluation.
 *
 * @property int $id
 * @property int $provider_evaluation_id
 * @property int $evaluation_criterion_id
 * @property string|null $score
 * @property string $note
 */
class ProviderEvaluationScore extends Model
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
            'provider_evaluation_id' => 'integer',
            'evaluation_criterion_id' => 'integer',
            'score' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Provider evaluations are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Provider evaluations are append-only.'));
    }

    /**
     * @return BelongsTo<EvaluationCriterion, $this>
     */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(EvaluationCriterion::class, 'evaluation_criterion_id');
    }
}
