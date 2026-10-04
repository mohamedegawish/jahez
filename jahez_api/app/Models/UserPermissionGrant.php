<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One permission granted to one IMC administrator (ADR-023). Only the permissions
 * App\Enums\Permission::grantedIndividually() lists are granted this way, and only from
 * the console (`php artisan jahez:permissions`), audited.
 *
 * @property int $id
 * @property int $user_id
 * @property string $permission
 * @property int|null $granted_by_user_id
 * @property string $reason
 * @property Carbon|null $created_at
 */
class UserPermissionGrant extends Model
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
            'user_id' => 'integer',
            'granted_by_user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
