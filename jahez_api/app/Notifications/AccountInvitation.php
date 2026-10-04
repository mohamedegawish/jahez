<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Invitation for an account created by an IMC administrator (ADR-011). The invitee
 * chooses a password through the standard password reset link, which also proves
 * ownership of the email address. Deliberately not queued: it is sent from inside the
 * SendAccountInvitation job, so the plaintext token never sits in a queue payload.
 */
class AccountInvitation extends ResetPassword
{
    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Jahez account')
            ->line('An account has been created for you on the Jahez platform.')
            ->action('Set your password', $this->resetUrl($notifiable))
            ->line('This link will expire in '.config('auth.passwords.users.expire').' minutes. You can request a new link from the "Forgot password" page at any time.');
    }
}
