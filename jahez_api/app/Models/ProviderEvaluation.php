<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An IMC evaluation of a provider against the DOC §6 criteria (OQ-13 interim). Each
 * criterion gets a written assessment; a score and the weighted total exist only when
 * the owner has approved a scale (config jahez.providers.evaluation.scale_max), and the
 * pass-mark comparison only when a pass mark is approved. Approval of the provider stays
 * a manual IMC decision. Entries are append-only: a re-evaluation is a new entry.
 *
 * @property int $id
 * @property int $service_provider_id
 * @property int $criteria_version
 * @property string $summary
 * @property Carbon $evaluated_on
 * @property int|null $scale_max
 * @property string|null $weighted_total
 * @property string|null $pass_mark
 * @property int $recorded_by_user_id
 * @property Carbon $created_at
 */
class ProviderEvaluation extends Model
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
            'service_provider_id' => 'integer',
            'criteria_version' => 'integer',
            'evaluated_on' => 'date',
            'scale_max' => 'integer',
            'weighted_total' => 'decimal:2',
            'pass_mark' => 'decimal:2',
            'recorded_by_user_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Provider evaluations are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Provider evaluations are append-only.'));
    }

    /**
     * @return BelongsTo<ServiceProvider, $this>
     */
    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class);
    }

    /**
     * @return HasMany<ProviderEvaluationScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(ProviderEvaluationScore::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * Whether the weighted total reaches the pass mark in force when the evaluation was
     * recorded; null when either is missing. Informational only.
     */
    public function meetsPassMark(): ?bool
    {
        if ($this->weighted_total === null || $this->pass_mark === null) {
            return null;
        }

        return self::hundredths($this->weighted_total) >= self::hundredths($this->pass_mark);
    }

    /**
     * The weighted total out of 100: the sum of weight × score / scale over the criteria,
     * rounded half up to two decimals. Integer arithmetic on hundredths, so no floating
     * point error. The aggregation itself is the plain reading of DOC §6 "weights total
     * 100%"; the scale it divides by is the owner-approved one (OQ-13).
     *
     * @param  list<array{weight: string, score: string}>  $criteria
     */
    public static function weightedTotal(array $criteria, int $scaleMax): string
    {
        $numerator = 0;
        foreach ($criteria as $criterion) {
            $numerator += self::hundredths($criterion['weight']) * self::hundredths($criterion['score']);
        }

        $denominator = $scaleMax * 100;
        $totalHundredths = intdiv(2 * $numerator + $denominator, 2 * $denominator);

        return sprintf('%d.%02d', intdiv($totalHundredths, 100), $totalHundredths % 100);
    }

    /**
     * A non-negative decimal string with at most two decimals ("4.5", "30.00") as an
     * integer number of hundredths.
     */
    public static function hundredths(string $decimal): int
    {
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
