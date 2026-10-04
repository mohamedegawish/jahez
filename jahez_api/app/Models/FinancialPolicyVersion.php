<?php

namespace App\Models;

use App\Billing\PolicyCalendar;
use App\Enums\FinancialPolicyVersionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One version of a financial policy (ADR-023): its values (`parameters`, validated for
 * the policy's kind by App\Billing\PolicyParameters) and its effective period. Values and
 * dates change only while it is a draft. Once approved, the version is the record that
 * agreements, contracts and invoices reference, so the only later changes are to its
 * status and an earlier end date (superseded or ended); booted() refuses anything else.
 * Versions are never deleted.
 *
 * @property int $id
 * @property int $financial_policy_id
 * @property int $version
 * @property FinancialPolicyVersionStatus $status
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property array<string, mixed> $parameters
 * @property string $change_reason
 * @property int $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property int|null $submitted_by_user_id
 * @property Carbon|null $submitted_at
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property int|null $superseded_by_version_id
 * @property int|null $status_changed_by_user_id
 * @property Carbon|null $status_changed_at
 * @property string|null $status_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FinancialPolicyVersion extends Model
{
    /**
     * The columns an approved version may still change.
     */
    private const CHANGEABLE_AFTER_APPROVAL = [
        'status', 'effective_to', 'superseded_by_version_id',
        'status_changed_by_user_id', 'status_changed_at', 'status_reason', 'updated_at',
    ];

    /**
     * Mirrors the column defaults so a model that was just created can be read without
     * reloading it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'effective_to' => null,
        'updated_by_user_id' => null,
        'submitted_by_user_id' => null,
        'submitted_at' => null,
        'decided_by_user_id' => null,
        'decided_at' => null,
        'decision_note' => null,
        'superseded_by_version_id' => null,
        'status_changed_by_user_id' => null,
        'status_changed_at' => null,
        'status_reason' => null,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'financial_policy_id' => 'integer',
            'version' => 'integer',
            'status' => FinancialPolicyVersionStatus::class,
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'parameters' => 'array',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
            'submitted_by_user_id' => 'integer',
            'submitted_at' => 'datetime',
            'decided_by_user_id' => 'integer',
            'decided_at' => 'datetime',
            'superseded_by_version_id' => 'integer',
            'status_changed_by_user_id' => 'integer',
            'status_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            $original = FinancialPolicyVersionStatus::tryFrom((string) $version->getRawOriginal('status'));
            if ($original === null || ! $original->isResolvable()) {
                return;
            }

            $changed = array_diff(array_keys($version->getDirty()), self::CHANGEABLE_AFTER_APPROVAL);
            if ($changed !== []) {
                throw new LogicException('An approved policy version is never edited: '.implode(', ', $changed).'.');
            }

            $originalEnd = $version->getRawOriginal('effective_to');
            if ($version->isDirty('effective_to') && ($version->effective_to === null || ($originalEnd !== null && $version->effective_to->toDateString() > (string) $originalEnd))) {
                throw new LogicException('An approved policy version can only end earlier, never later.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Policy versions are never deleted.'));
    }

    /**
     * @return BelongsTo<FinancialPolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(FinancialPolicy::class, 'financial_policy_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /**
     * Whether the version applies on the day: resolvable and inside its period.
     */
    public function covers(CarbonImmutable $day): bool
    {
        $date = $day->toDateString();

        return $this->status->isResolvable()
            && $this->effective_from->toDateString() <= $date
            && ($this->effective_to === null || $this->effective_to->toDateString() >= $date);
    }

    /**
     * The status as an administrator reads it: an approved (or superseded, or ended)
     * version is `scheduled` before its period, `active` during it and `expired` after;
     * any other version reports its status.
     */
    public function effectiveStatus(?CarbonImmutable $today = null): string
    {
        if (! $this->status->isResolvable()) {
            return $this->status->value;
        }

        $date = ($today ?? PolicyCalendar::today())->toDateString();

        return match (true) {
            $this->effective_from->toDateString() > $date => 'scheduled',
            $this->effective_to !== null && $this->effective_to->toDateString() < $date => 'expired',
            default => 'active',
        };
    }

    /**
     * Whether the users who drafted, last edited or submitted the version include the user.
     * Such a user may not approve or reject it (ADR-023: preparing and approving a rule
     * are done by different people).
     */
    public function wasPreparedBy(User $user): bool
    {
        return in_array($user->id, array_filter([$this->created_by_user_id, $this->updated_by_user_id, $this->submitted_by_user_id]), true);
    }

    /**
     * A short reference recorded with the snapshot of anything made under the version.
     *
     * @return array{id: int, policy_id: int, version: int}
     */
    public function reference(): array
    {
        return ['id' => $this->id, 'policy_id' => $this->financial_policy_id, 'version' => $this->version];
    }
}
