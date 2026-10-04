<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;

/**
 * Laravel's password reset email. Deliberately not queued: it is sent from inside the
 * SendPasswordResetLink job, so the plaintext token never sits in a queue payload.
 * The link target is configured in AppServiceProvider (ResetPassword::createUrlUsing).
 */
class PasswordResetLink extends ResetPassword {}
