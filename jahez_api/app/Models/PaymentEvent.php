<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A verified gateway event and what the processor did with it (ADR-017). Append-only;
 * the outcome is set once, when the event is processed.
 *
 * @property int $id
 * @property string $gateway
 * @property string $event_id
 * @property int|null $payment_id
 * @property string $gateway_reference
 * @property PaymentStatus $reported_status
 * @property string $reported_amount
 * @property string $reported_currency
 * @property string $source
 * @property string $outcome
 * @property Carbon $created_at
 */
class PaymentEvent extends Model
{
    public const UPDATED_AT = null;

    public const OUTCOME_APPLIED = 'applied';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public const OUTCOME_NO_CHANGE = 'no_change';

    public const OUTCOME_UNKNOWN_PAYMENT = 'unknown_payment';

    public const OUTCOME_AMOUNT_MISMATCH = 'amount_mismatch';

    public const OUTCOME_IGNORED_TRANSITION = 'ignored_transition';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_id' => 'integer',
            'reported_status' => PaymentStatus::class,
            'reported_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $event): void {
            if ($event->getOriginal('outcome') !== 'processing') {
                throw new LogicException('Payment events are append-only.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Payment events are append-only.'));
    }
}
