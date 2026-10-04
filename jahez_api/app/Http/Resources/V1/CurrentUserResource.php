<?php

namespace App\Http\Resources\V1;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The authenticated user, with the platform-wide permissions a client may use to
 * decide which actions to offer. The server still authorizes every request.
 *
 * @mixin User
 */
class CurrentUserResource extends UserResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'email_notifications' => (bool) $this->email_notifications,
            'permissions' => array_map(
                fn (Permission $permission): string => $permission->value,
                $this->permissions(),
            ),
        ];
    }
}
