<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends platform-event notifications (ADR-020).
 *
 * - **After the commit:** called inside the business transaction, the notifications are
 *   sent only once it commits, and never for a rolled-back change.
 * - **Never fails the business change:** an error while notifying is reported to the log
 *   and swallowed.
 * - **No duplicates:** each notification's id is derived from the event key and the
 *   recipient, so an event processed twice stores, and emails, at most one copy.
 * - **Recipients:** active accounts only; email only to those who kept it on, through
 *   the queue (PlatformEventMail).
 */
class PlatformNotifier
{
    /**
     * Notify every active member of a factory.
     *
     * @param  array{type: string, id: int}|null  $subject
     */
    public static function factoryMembers(int $factoryId, NotificationEvent $event, string $eventKey, string $body, ?string $link, ?array $subject = null): void
    {
        self::send(fn () => User::query()->where('factory_id', $factoryId), $event, $eventKey, $body, $link, $subject);
    }

    /**
     * Notify every active member of a service provider.
     *
     * @param  array{type: string, id: int}|null  $subject
     */
    public static function providerMembers(int $serviceProviderId, NotificationEvent $event, string $eventKey, string $body, ?string $link, ?array $subject = null): void
    {
        self::send(fn () => User::query()->where('service_provider_id', $serviceProviderId), $event, $eventKey, $body, $link, $subject);
    }

    /**
     * Notify every active IMC account that holds the permission.
     *
     * @param  array{type: string, id: int}|null  $subject
     */
    public static function imcWith(Permission $permission, NotificationEvent $event, string $eventKey, string $body, ?string $link, ?array $subject = null): void
    {
        $roles = array_values(array_filter(Role::cases(), fn (Role $role): bool => in_array($permission, $role->permissions(), true)));

        self::send(fn () => User::query()->whereIn('role', $roles)->whereNull('factory_id')->whereNull('service_provider_id'), $event, $eventKey, $body, $link, $subject);
    }

    /**
     * @param  callable(): Builder<User>  $recipients
     * @param  array{type: string, id: int}|null  $subject
     */
    private static function send(callable $recipients, NotificationEvent $event, string $eventKey, string $body, ?string $link, ?array $subject): void
    {
        DB::afterCommit(function () use ($recipients, $event, $eventKey, $body, $link, $subject): void {
            try {
                $recipients()->whereNull('deactivated_at')->orderBy('id')->each(
                    fn (User $user) => self::deliver($user, $event, $eventKey, $body, $link, $subject),
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * @param  array{type: string, id: int}|null  $subject
     */
    private static function deliver(User $user, NotificationEvent $event, string $eventKey, string $body, ?string $link, ?array $subject): void
    {
        try {
            $id = self::idFor($eventKey, $user);

            if (DatabaseNotification::query()->whereKey($id)->exists()) {
                return;
            }

            $notification = new PlatformNotification($event, $body, $link, $subject);
            $notification->id = $id;
            $user->notifyNow($notification);

            if ((bool) config('jahez.notifications.mail', true) && $user->email_notifications) {
                $user->notify(new PlatformEventMail($event, $body, $link));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * A stable UUID-shaped id for the event key and the recipient.
     */
    public static function idFor(string $eventKey, Model $recipient): string
    {
        $hash = hash('sha256', $eventKey.'|'.$recipient->getKey());

        return sprintf('%s-%s-%s-%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12));
    }
}
