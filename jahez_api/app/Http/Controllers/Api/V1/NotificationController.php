<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListNotificationsRequest;
use App\Http\Resources\V1\NotificationResource;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The signed-in account's in-app notifications (ADR-020), newest first. Only the
 * account's own notifications exist here: another account's id is 404.
 */
class NotificationController extends Controller
{
    public function index(ListNotificationsRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        $notifications = $user->notifications()
            ->when($request->boolean('filter.unread'), fn ($query) => $query->whereNull('read_at'))
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return NotificationResource::collection($notifications)->additional([
            'meta' => ['unread_count' => $user->unreadNotifications()->count()],
        ]);
    }

    public function read(string $notification, #[CurrentUser] User $user): NotificationResource
    {
        $record = $user->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        return new NotificationResource($record);
    }

    public function readAll(#[CurrentUser] User $user): JsonResponse
    {
        $user->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['unread_count' => 0]]);
    }
}
