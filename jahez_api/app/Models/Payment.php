<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A payment of an issued invoice (ADR-017, ADR-023): through the configured gateway,
 * whose status changes only on verified gateway evidence (App\Billing\PaymentProcessor),
 * or an authorised manual entry of money received outside the platform, recorded as
 * succeeded by an administrator granted payments.record with its evidence reference.
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $gateway
 * @property string|null $gateway_reference
 * @property string $idempotency_key
 * @property PaymentStatus $status
 * @property string $amount
 * @property string $currency
 * @property string|null $checkout_url
 * @property string|null $failure_reason
 * @property int $initiated_by_user_id
 * @property string $method
 * @property int|null $recorded_by_user_id
 * @property CarbonImmutable|null $received_on
 * @property string|null $evidence_note
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 */
class Payment extends Model
{
    public const METHOD_GATEWAY = 'gateway';

    /** Also the `gateway` value of a manual entry, so its reference is unique among them. */
    public const METHOD_MANUAL = 'manual';

    /**
     * Mirrors the column defaults so a model that was just created can be read without
     * reloading it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'gateway_reference' => null,
        'checkout_url' => null,
        'failure_reason' => null,
        'status_changed_at' => null,
        'method' => self::METHOD_GATEWAY,
        'recorded_by_user_id' => null,
        'received_on' => null,
        'evidence_note' => null,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_id' => 'integer',
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'initiated_by_user_id' => 'integer',
            'status_changed_at' => 'datetime',
            'recorded_by_user_id' => 'integer',
            'received_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
