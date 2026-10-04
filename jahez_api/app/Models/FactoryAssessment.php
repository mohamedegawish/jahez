<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A manual IMC classification of a factory (ADR-016): the maturity tier an IMC
 * administrator chose, with a justification. It carries no score, because no scoring
 * rules are approved (OQ-06, OQ-07). Entries are never updated or deleted, so the
 * classification history cannot be rewritten; a reclassification is a new entry.
 *
 * @property int $id
 * @property int $factory_id
 * @property int $maturity_tier_id
 * @property string $justification
 * @property Carbon $assessed_on
 * @property int $recorded_by_user_id
 * @property Carbon $created_at
 */
class FactoryAssessment extends Model
{
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
            'maturity_tier_id' => 'integer',
            'recorded_by_user_id' => 'integer',
            'assessed_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Factory assessments are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Factory assessments are append-only.'));
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
     * @return BelongsTo<MaturityTier, $this>
     */
    public function maturityTier(): BelongsTo
    {
        return $this->belongsTo(MaturityTier::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
