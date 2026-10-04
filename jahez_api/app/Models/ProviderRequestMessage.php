<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A negotiation message between a factory and one provider (ADR-015). Messages are
 * conversation, never formal offers, and are never edited or deleted.
 *
 * @property int $id
 * @property int $provider_request_id
 * @property int $author_user_id
 * @property string $author_side
 * @property string $body
 * @property Carbon $created_at
 */
class ProviderRequestMessage extends Model
{
    public const UPDATED_AT = null;

    public const SIDE_FACTORY = 'factory';

    public const SIDE_PROVIDER = 'provider';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_request_id' => 'integer',
            'author_user_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Negotiation messages are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Negotiation messages are append-only.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
