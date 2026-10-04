<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An answer choice of a readiness question (أ ب ج د) and the points it is worth
 * (ADR-018). Points are copied onto each answer when an assessment is submitted.
 *
 * @property int $id
 * @property int $readiness_question_id
 * @property string $code
 * @property string $label_ar
 * @property string $text_ar
 * @property int $points
 * @property int $sort_order
 */
class ReadinessChoice extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'readiness_question_id',
        'code',
        'label_ar',
        'text_ar',
        'points',
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
            'readiness_question_id' => 'integer',
            'points' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ReadinessQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(ReadinessQuestion::class, 'readiness_question_id');
    }
}
