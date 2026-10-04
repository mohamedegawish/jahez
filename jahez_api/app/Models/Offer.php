<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One version of a provider's formal offer (ADR-015). A revision is a new version;
 * versions are never changed or deleted. The price is informational and in EGP (owner
 * decision 2026-10-03); accepting an offer creates no contract, invoice or payment.
 *
 * @property int $id
 * @property int $provider_request_id
 * @property int $version
 * @property string $scope
 * @property string $deliverables
 * @property int $duration_days
 * @property string $price_amount
 * @property string $currency
 * @property Carbon|null $valid_until
 * @property int $author_user_id
 * @property Carbon $created_at
 */
class Offer extends Model
{
    public const UPDATED_AT = null;

    /**
     * The only currency offers may use (owner decision 2026-10-03).
     */
    public const CURRENCY = 'EGP';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_request_id' => 'integer',
            'version' => 'integer',
            'duration_days' => 'integer',
            'price_amount' => 'decimal:2',
            'valid_until' => 'date',
            'author_user_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Offer versions are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Offer versions are append-only.'));
    }

    /**
     * Whether the validity date the provider gave has passed: an offer is valid through
     * its `valid_until` day (application time zone, UTC). Without a date it never expires.
     */
    public function hasExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->lt(today());
    }

    /**
     * @return BelongsTo<ProviderRequest, $this>
     */
    public function providerRequest(): BelongsTo
    {
        return $this->belongsTo(ProviderRequest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
