<?php

use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;

/**
 * @return array<string, string>
 */
function newPasswordPayload(string $token, string $email, string $password = 'a-brand-new-passphrase'): array
{
    return [
        'token' => $token,
        'email' => $email,
        'password' => $password,
        'password_confirmation' => $password,
    ];
}

describe('forgot password', function () {
    it('queues the same job and returns the same 202 whether or not the account exists', function (string $email) {
        Queue::fake([SendPasswordResetLink::class]);

        $response = $this->postJson(route('api.v1.auth.password.email'), ['email' => $email]);

        $response->assertAccepted()
            ->assertExactJson(['data' => ['message' => PasswordResetLinkController::RESPONSE_MESSAGE]]);
        Queue::assertPushed(SendPasswordResetLink::class, fn (SendPasswordResetLink $job): bool => $job->email === $email);
    })->with([
        'active account' => [fn (): string => User::factory()->create()->email],
        'unknown email' => [fn (): string => 'nobody@example.test'],
        'deactivated account' => [fn (): string => User::factory()->deactivated()->create()->email],
    ]);

    it('passes the requesting address to the job for its audit entry', function () {
        Queue::fake([SendPasswordResetLink::class]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson(route('api.v1.auth.password.email'), ['email' => 'member@example.test'])
            ->assertAccepted();

        Queue::assertPushed(SendPasswordResetLink::class, fn (SendPasswordResetLink $job): bool => $job->ipAddress === '203.0.113.9');
    });

    it('creates no reset token during the request, so none can reach the queue payload', function () {
        $user = User::factory()->create();
        Queue::fake([SendPasswordResetLink::class]);

        $this->postJson(route('api.v1.auth.password.email'), ['email' => $user->email])->assertAccepted();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    });

    it('returns 429 after five requests a minute from one address', function () {
        Queue::fake([SendPasswordResetLink::class]);
        foreach (range(1, 5) as $attempt) {
            $this->postJson(route('api.v1.auth.password.email'), ['email' => "person{$attempt}@example.test"])->assertAccepted();
        }

        $response = $this->postJson(route('api.v1.auth.password.email'), ['email' => 'person6@example.test']);

        $response->assertTooManyRequests();
    });
});

describe('reset password', function () {
    it('sets the new password, revokes existing tokens and verifies the email', function () {
        $user = User::factory()->unverified()->create(['email' => 'member@example.test']);
        bearerTokenFor($user);
        $resetToken = Password::broker()->createToken($user);

        $response = $this->postJson(route('api.v1.auth.password.store'), newPasswordPayload($resetToken, 'member@example.test'));

        $response->assertOk()->assertJsonPath('data.message', 'Your password has been set.');
        $user->refresh();
        expect(Hash::check('a-brand-new-passphrase', $user->password))->toBeTrue()
            ->and($user->email_verified_at)->not->toBeNull();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    });

    it('rejects a reset token that was already used with 422', function () {
        $user = User::factory()->create(['email' => 'member@example.test']);
        $resetToken = Password::broker()->createToken($user);
        $this->postJson(route('api.v1.auth.password.store'), newPasswordPayload($resetToken, 'member@example.test'))->assertOk();

        $response = $this->postJson(route('api.v1.auth.password.store'), newPasswordPayload($resetToken, 'member@example.test', 'another-new-passphrase'));

        $response->assertUnprocessable()->assertJsonPath('errors.token.0', NewPasswordController::INVALID_LINK_MESSAGE);
    });

    it('rejects an expired reset token with 422', function () {
        $user = User::factory()->create(['email' => 'member@example.test']);
        $resetToken = Password::broker()->createToken($user);
        $this->travel(61)->minutes();

        $response = $this->postJson(route('api.v1.auth.password.store'), newPasswordPayload($resetToken, 'member@example.test'));

        $response->assertUnprocessable()->assertJsonPath('errors.token.0', NewPasswordController::INVALID_LINK_MESSAGE);
    });

    it('rejects a valid token of a deactivated account with 422 and keeps the old password', function () {
        $user = User::factory()->deactivated()->create(['email' => 'member@example.test', 'password' => 'the-old-passphrase']);
        $resetToken = Password::broker()->createToken($user);

        $response = $this->postJson(route('api.v1.auth.password.store'), newPasswordPayload($resetToken, 'member@example.test'));

        $response->assertUnprocessable()->assertJsonPath('errors.token.0', NewPasswordController::INVALID_LINK_MESSAGE);
        expect(Hash::check('the-old-passphrase', $user->refresh()->password))->toBeTrue();
    });

    it('rejects an unknown email with the same 422 message', function () {
        $response = $this->postJson(route('api.v1.auth.password.store'), newPasswordPayload('any-token', 'nobody@example.test'));

        $response->assertUnprocessable()->assertJsonPath('errors.token.0', NewPasswordController::INVALID_LINK_MESSAGE);
    });

    it('compares a hash for an unknown email so timing does not reveal accounts', function () {
        Hash::shouldReceive('check')->once()->with('any-token', LoginRequest::UNMATCHABLE_PASSWORD_HASH)->andReturnFalse();

        $response = $this->postJson(route('api.v1.auth.password.store'), newPasswordPayload('any-token', 'nobody@example.test'));

        $response->assertUnprocessable();
    });

    it('rejects a password that breaks the policy with 422', function (string $password, string $message) {
        $response = $this->postJson(route('api.v1.auth.password.store'), newPasswordPayload('any-token', 'member@example.test', $password));

        $response->assertUnprocessable()->assertJsonPath('errors.password.0', $message);
    })->with([
        'shorter than 12 characters' => ['short-pass1', 'The password field must be at least 12 characters.'],
        'longer than 72 bytes' => [str_repeat('ب', 37), 'The password is too long.'],
    ]);
});
