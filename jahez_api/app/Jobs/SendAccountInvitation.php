<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Password;

/**
 * Creates a set-password token for a new account and emails the invitation (ADR-011).
 * The token is created in the queue worker, so it never appears in the job payload,
 * which holds only the user's identifier.
 */
class SendAccountInvitation implements ShouldQueue
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

    /**
     * Discard the job if the account was deleted before it ran.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly User $user) {}

    public function handle(): void
    {
        if (! $this->user->isActive()) {
            return;
        }

        $this->user->notify(new AccountInvitation(Password::broker()->createToken($this->user)));
    }
}
