<?php

namespace App\Models;

use App\Enums\AgreementReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * IMC's final decision on an agreement (ADR-020): approved or rejected, with a reason
 * for a rejection. Append-only and at most one per agreement.
 *
 * @property int $id
 * @property int $agreement_id
 * @property AgreementReviewStatus $decision
 * @property string|null $reason
 * @property int|null $reviewed_by_user_id
 * @property Carbon $decided_at
 */
class AgreementReview extends Model
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
            'agreement_id' => 'integer',
            'decision' => AgreementReviewStatus::class,
            'reviewed_by_user_id' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Agreement reviews are never changed.'));
        static::deleting(fn (): never => throw new LogicException('Agreement reviews are never changed.'));
    }

    /**
     * @return BelongsTo<Agreement, $this>
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
