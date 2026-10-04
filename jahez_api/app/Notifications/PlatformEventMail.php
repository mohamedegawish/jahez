<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email copy of an in-app notification (ADR-020), queued after the event's
 * transaction commits, so a mail failure never rolls back the business change and never
 * delays the request. The email says what happened and links to the record; it never
 * carries message text, offer terms, prices or documents.
 */
class PlatformEventMail extends Notification implements ShouldQueue
{
    use Queueable;

    /** Retries on a mail transport failure, then the job fails (see failed_jobs). */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public NotificationEvent $event,
        public string $body,
        public ?string $link,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('جاهز: '.$this->event->title())
            ->line($this->body);

        if ($this->link !== null) {
            $message->action('عرض التفاصيل', rtrim((string) config('api.frontend_url'), '/').$this->link);
        }

        return $message->line('سجّلوا الدخول إلى منصة جاهز لمتابعة التفاصيل.');
    }
}
