<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Failed attempts allowed per email and IP address within one minute.
     */
    public const MAX_FAILED_ATTEMPTS = 5;

    /**
     * Failed attempts allowed per email from all addresses within 15 minutes, against
     * brute force spread over many IP addresses. Provisional value (ADR-011).
     */
    public const MAX_FAILED_ATTEMPTS_PER_ACCOUNT = 20;

    /**
     * A cost-12 bcrypt hash of a random secret that was discarded, so no password matches
     * it. Unknown emails are checked against it, so they cost one hash comparison like
     * known ones. Cost 12 matches the production BCRYPT_ROUNDS default; keep them in step.
     */
    public const UNMATCHABLE_PASSWORD_HASH = '$2y$12$AKQ9OcIjjySA6ntVPQO7XuGqv3ORUrbLHHeroOdkWvFYv5YqMNz/y';

    /**
     * The bcrypt cost of UNMATCHABLE_PASSWORD_HASH; `app:check-production` verifies
     * that BCRYPT_ROUNDS matches it.
     */
    public const UNMATCHABLE_PASSWORD_HASH_COST = 12;

    private const ADDRESS_DECAY_SECONDS = 60;

    private const ACCOUNT_DECAY_SECONDS = 900;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * Resolve the user for valid credentials of an active account.
     *
     * Unknown email, wrong password and deactivated account all fail with the same
     * message, and an unknown email still costs one password-hash comparison, so neither
     * the response nor its timing reveals whether an account exists.
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $user = $this->account();

        $passwordMatches = Hash::check(
            $this->string('password')->toString(),
            $user !== null ? $user->password : self::UNMATCHABLE_PASSWORD_HASH,
        );

        if ($user === null || ! $passwordMatches || ! $user->isActive()) {
            RateLimiter::hit($this->addressThrottleKey(), self::ADDRESS_DECAY_SECONDS);
            RateLimiter::hit($this->accountThrottleKey(), self::ACCOUNT_DECAY_SECONDS);

            AuditLog::record(AuditEvent::LoginFailed, subject: $user, metadata: [
                'email' => $this->string('email')->toString(),
                'reason' => match (true) {
                    $user === null => 'unknown_account',
                    ! $passwordMatches => 'wrong_password',
                    default => 'deactivated_account',
                },
            ]);

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        RateLimiter::clear($this->addressThrottleKey());
        RateLimiter::clear($this->accountThrottleKey());

        return $user;
    }

    /**
     * Refuse the attempt while a limit is reached. Only the first refusal of each lock
     * period is audited, so an attacker hammering a locked account cannot turn every
     * refused request into a database write.
     */
    private function ensureIsNotRateLimited(): void
    {
        $lockedKey = match (true) {
            RateLimiter::tooManyAttempts($this->addressThrottleKey(), self::MAX_FAILED_ATTEMPTS) => $this->addressThrottleKey(),
            RateLimiter::tooManyAttempts($this->accountThrottleKey(), self::MAX_FAILED_ATTEMPTS_PER_ACCOUNT) => $this->accountThrottleKey(),
            default => null,
        };

        if ($lockedKey === null) {
            return;
        }

        $secondsUntilUnlocked = RateLimiter::availableIn($lockedKey);

        if (Cache::add($lockedKey.':audited', true, max(1, $secondsUntilUnlocked))) {
            AuditLog::record(AuditEvent::LoginThrottled, metadata: [
                'email' => $this->string('email')->toString(),
                'limit' => $lockedKey === $this->accountThrottleKey() ? 'account' : 'address',
            ]);
        }

        throw new ThrottleRequestsException(
            headers: ['Retry-After' => $secondsUntilUnlocked],
        );
    }

    /**
     * The account for the submitted email. MySQL compares emails with the column's
     * collation, which ignores letter case and accents and some invisible characters
     * (for example U+FE0F), so a database match counts only when the address is the same
     * apart from letter case. Every spelling that can log in to an account therefore
     * maps to that account's throttle keys.
     */
    private function account(): ?User
    {
        $email = $this->string('email')->toString();
        $user = User::query()->where('email', $email)->first();

        return $user !== null && Str::lower($user->email) === Str::lower($email) ? $user : null;
    }

    /**
     * Throttle keys are hashed so they have a fixed length: the database cache store
     * keeps keys in a 255-character column and silently stops counting longer ones.
     */
    private function addressThrottleKey(): string
    {
        return 'login:'.hash('sha256', $this->normalizedEmail().'|'.$this->ip());
    }

    private function accountThrottleKey(): string
    {
        return 'login-account:'.hash('sha256', $this->normalizedEmail());
    }

    private function normalizedEmail(): string
    {
        return Str::transliterate(Str::lower($this->string('email')->toString()));
    }
}
