<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when someone registers an organization with an email that already has an account
 * (ADR-019). The registration response never says so (ADR-011), so this email is how the
 * owner of the address finds out. Not queued: it is sent from inside the
 * RegisterOrganization job.
 */
class RegistrationForExistingAccount extends Notification
{
    use Queueable;

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Registration attempt on Jahez')
            ->line('Someone tried to register a new organization on the Jahez platform with this email address, which already has an account. No new account was created.')
            ->line('If it was you, sign in with your existing account, or use the "Forgot password" page to choose a new password.')
            ->line('If it was not you, you can ignore this email.');
    }
}
