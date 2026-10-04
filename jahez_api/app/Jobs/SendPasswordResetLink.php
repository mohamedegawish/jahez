<?php

namespace App\Jobs;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * Creates and emails a password reset link in the queue worker.
 *
 * The "forgot password" endpoint dispatches this job for every request, so its response
 * does the same work whether or not the account exists. The reset token is created here,
 * so it never appears in the job payload, which holds only the submitted email address
 * and the requesting IP address (for the audit entry, as the worker has no request).
 */
class SendPasswordResetLink implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying.
     *
     * @var list<int>
     */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $email, public readonly ?string $ipAddress = null) {}

    /**
     * Send the link to an active account; do nothing for unknown or deactivated ones.
     *
     * When the email cannot be sent, the token created for it is deleted before the job
     * fails. Otherwise the broker would treat the retry as a repeated request within its
     * throttle window and send nothing, so the user would never receive a link.
     */
    public function handle(): void
    {
        $user = User::query()->where('email', $this->email)->first();

        if ($user === null || ! $user->isActive()) {
            return;
        }

        try {
            $status = Password::broker()->sendResetLink(['email' => $user->email]);
        } catch (Throwable $exception) {
            Password::broker()->deleteToken($user);

            throw $exception;
        }

        AuditLog::record(AuditEvent::PasswordResetRequested, subject: $user, metadata: ['status' => $status], ipAddress: $this->ipAddress);
    }
}
