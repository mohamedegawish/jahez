<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * How far a participant has read a negotiation (ADR-020): the last message id they saw.
 * Messages of the other side after it are unread. The mark only moves forward.
 *
 * @property int $id
 * @property int $provider_request_id
 * @property int $user_id
 * @property int $last_read_message_id
 * @property Carbon $read_at
 */
class ProviderRequestRead extends Model
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
            'provider_request_id' => 'integer',
            'user_id' => 'integer',
            'last_read_message_id' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    /**
     * Record that the user has read the thread up to the message. An older mark never
     * replaces a newer one, so out-of-order requests cannot bring messages back as unread.
     */
    public static function markRead(int $providerRequestId, User $user, int $messageId): void
    {
        $moved = self::query()
            ->where('provider_request_id', $providerRequestId)
            ->where('user_id', $user->id)
            ->where('last_read_message_id', '<', $messageId)
            ->update(['last_read_message_id' => $messageId, 'read_at' => now()]);

        if ($moved > 0 || self::query()->where('provider_request_id', $providerRequestId)->where('user_id', $user->id)->exists()) {
            return;
        }

        try {
            $read = new self;
            $read->provider_request_id = $providerRequestId;
            $read->user_id = $user->id;
            $read->last_read_message_id = $messageId;
            $read->read_at = now();
            $read->save();
        } catch (UniqueConstraintViolationException) {
            self::markRead($providerRequestId, $user, $messageId);
        }
    }
}
