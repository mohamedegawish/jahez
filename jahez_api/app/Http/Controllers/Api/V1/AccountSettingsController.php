<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CurrentUserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in account's own settings (ADR-020): the display name and whether platform
 * notifications are also emailed. The email address, role, organization and password
 * are not changed here (passwords change through the reset flow, ADR-011).
 */
class AccountSettingsController extends Controller
{
    public function update(Request $request, #[CurrentUser] User $user): CurrentUserResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'email_notifications' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($validated, $user): void {
            $previousName = $user->name;

            if (array_key_exists('name', $validated)) {
                $user->name = trim((string) $validated['name']);
            }
            if (array_key_exists('email_notifications', $validated)) {
                $user->email_notifications = (bool) $validated['email_notifications'];
            }
            $user->save();

            if ($user->name !== $previousName) {
                AuditLog::record(AuditEvent::UserRenamed, $user, $user, ['from' => $previousName, 'to' => $user->name]);
            }
        });

        return new CurrentUserResource($user->refresh()->loadMissing(['industrialFactory', 'serviceProvider']));
    }
}
