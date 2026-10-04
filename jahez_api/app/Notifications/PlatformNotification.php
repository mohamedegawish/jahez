<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use Illuminate\Notifications\Notification;

/**
 * An in-app notification of a platform event (ADR-020), stored with Laravel's database
 * channel. Sent synchronously by PlatformNotifier after the event's transaction commits,
 * so it is stored even when no queue worker runs. The id is set by the notifier, from
 * the event and the recipient, so a retried event stores no second copy.
 */
class PlatformNotification extends Notification
{
    /**
     * @param  array{type: string, id: int}|null  $subject
     */
    public function __construct(
        public NotificationEvent $event,
        public string $body,
        public ?string $link,
        public ?array $subject,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event->value,
            'title' => $this->event->title(),
            'body' => $this->body,
            'link' => $this->link,
            'subject' => $this->subject,
        ];
    }
}
