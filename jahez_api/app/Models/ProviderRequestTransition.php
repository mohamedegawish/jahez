<?php

namespace App\Models;

use App\Enums\ProviderRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One status change of a provider request, shown to its two parties as the thread's
 * history. `from_status` is null for the creation. Append-only. The actor is null for a
 * change no user made.
 *
 * @property int $id
 * @property int $provider_request_id
 * @property ProviderRequestStatus|null $from_status
 * @property ProviderRequestStatus $to_status
 * @property string|null $reason
 * @property int|null $actor_user_id
 * @property Carbon $created_at
 */
class ProviderRequestTransition extends Model
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
            'provider_request_id' => 'integer',
            'from_status' => ProviderRequestStatus::class,
            'to_status' => ProviderRequestStatus::class,
            'actor_user_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Provider request history is append-only.'));
        static::deleting(fn (): never => throw new LogicException('Provider request history is append-only.'));
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
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
