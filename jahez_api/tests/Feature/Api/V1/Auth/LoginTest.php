<?php

use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

it('returns an expiring bearer token and the current user for valid credentials', function () {
    $this->travelTo('2026-10-02 10:00:00');
    $admin = User::factory()->imcAdmin()->create(['email' => 'admin@example.test', 'password' => 'correct-horse-battery']);

    $response = $this->postJson(route('api.v1.auth.login'), [
        'email' => 'admin@example.test',
        'password' => 'correct-horse-battery',
        'device_name' => 'Admin laptop',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.expires_at', '2026-10-02T18:00:00Z')
        ->assertJsonPath('data.user.id', $admin->id)
        ->assertJsonPath('data.user.role', 'imc_admin');
    expect($response->json('data.access_token'))->toBeString()->not->toBeEmpty();
    $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $admin->id, 'name' => 'Admin laptop']);
});

it('issues a token that authenticates later requests', function () {
    User::factory()->create(['email' => 'member@example.test', 'password' => 'correct-horse-battery']);
    $accessToken = $this->postJson(route('api.v1.auth.login'), [
        'email' => 'member@example.test',
        'password' => 'correct-horse-battery',
        'device_name' => 'Phone',
    ])->json('data.access_token');

    $response = $this->withToken($accessToken)->getJson(route('api.v1.me'));

    $response->assertOk()->assertJsonPath('data.email', 'member@example.test');
});

it('rejects invalid credentials with one generic 422 message and issues no token', function (array $credentials) {
    [$email, $password] = $credentials;

    $response = $this->postJson(route('api.v1.auth.login'), [
        'email' => $email,
        'password' => $password,
        'device_name' => 'Phone',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'wrong password' => [fn (): array => [User::factory()->create(['password' => 'correct-horse-battery'])->email, 'wrong-password-1']],
    'unknown email' => [fn (): array => ['nobody@example.test', 'correct-horse-battery']],
    'deactivated account' => [fn (): array => [User::factory()->deactivated()->create(['password' => 'correct-horse-battery'])->email, 'correct-horse-battery']],
]);

it('compares a password hash even for an unknown email so timing does not reveal accounts', function () {
    Hash::shouldReceive('check')->once()->with('whatever-password', LoginRequest::UNMATCHABLE_PASSWORD_HASH)->andReturnFalse();

    $response = $this->postJson(route('api.v1.auth.login'), [
        'email' => 'nobody@example.test',
        'password' => 'whatever-password',
        'device_name' => 'Phone',
    ]);

    $response->assertUnprocessable();
});

it('rejects a request without credentials with 422', function () {
    $response = $this->postJson(route('api.v1.auth.login'), []);

    $response->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'The email field is required.')
        ->assertJsonPath('errors.password.0', 'The password field is required.')
        ->assertJsonPath('errors.device_name.0', 'The device name field is required.');
});

it('returns 429 after five failed attempts, even when the sixth uses the right password', function () {
    User::factory()->create(['email' => 'member@example.test', 'password' => 'correct-horse-battery']);
    foreach (range(1, LoginRequest::MAX_FAILED_ATTEMPTS) as $attempt) {
        $this->postJson(route('api.v1.auth.login'), [
            'email' => 'member@example.test',
            'password' => 'wrong-password-'.$attempt,
            'device_name' => 'Phone',
        ])->assertUnprocessable();
    }

    $response = $this->postJson(route('api.v1.auth.login'), [
        'email' => 'member@example.test',
        'password' => 'correct-horse-battery',
        'device_name' => 'Phone',
    ]);

    $response->assertTooManyRequests()
        ->assertJsonPath('code', 'too_many_requests')
        ->assertHeader('Retry-After');
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('returns 429 for an account after twenty failed attempts spread over many addresses', function () {
    User::factory()->create(['email' => 'admin@example.test', 'password' => 'correct-horse-battery']);
    foreach (range(1, LoginRequest::MAX_FAILED_ATTEMPTS_PER_ACCOUNT) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.1.{$attempt}"])
            ->postJson(route('api.v1.auth.login'), ['email' => 'admin@example.test', 'password' => 'wrong-password-1', 'device_name' => 'Bot'])
            ->assertUnprocessable();
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.1'])
        ->postJson(route('api.v1.auth.login'), ['email' => 'admin@example.test', 'password' => 'correct-horse-battery', 'device_name' => 'Laptop']);

    $response->assertTooManyRequests()->assertHeader('Retry-After');
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('accepts the email address in any letter case', function () {
    User::factory()->create(['email' => 'member@example.test', 'password' => 'correct-horse-battery']);

    $response = $this->postJson(route('api.v1.auth.login'), [
        'email' => 'Member@Example.TEST',
        'password' => 'correct-horse-battery',
        'device_name' => 'Phone',
    ]);

    $response->assertOk();
});

it('keeps a locked account locked when the email is respelled with characters the database ignores', function () {
    User::factory()->create(['email' => 'member@example.test', 'password' => 'correct-horse-battery']);
    foreach (range(1, LoginRequest::MAX_FAILED_ATTEMPTS) as $attempt) {
        $this->postJson(route('api.v1.auth.login'), [
            'email' => 'member@example.test',
            'password' => 'wrong-password-'.$attempt,
            'device_name' => 'Phone',
        ])->assertUnprocessable();
    }

    $response = $this->postJson(route('api.v1.auth.login'), [
        'email' => "mem\u{FE0F}ber@example.test",
        'password' => 'correct-horse-battery',
        'device_name' => 'Phone',
    ]);

    $response->assertUnprocessable()->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('counts failed attempts for a long email address when the limiter uses the database cache store', function (int $failedAttempts, bool $spreadOverAddresses) {
    $databaseLimiter = new CacheRateLimiter(Cache::store('database'));
    foreach (['api', 'password-reset'] as $limiterName) {
        $databaseLimiter->for($limiterName, RateLimiter::limiter($limiterName));
    }
    RateLimiter::swap($databaseLimiter);
    $email = str_repeat('m', 230).'@example.test';
    User::factory()->create(['email' => $email, 'password' => 'correct-horse-battery']);
    foreach (range(1, $failedAttempts) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => $spreadOverAddresses ? "10.0.1.{$attempt}" : '10.0.0.1'])
            ->postJson(route('api.v1.auth.login'), ['email' => $email, 'password' => 'wrong-password-'.$attempt, 'device_name' => 'Phone'])
            ->assertUnprocessable();
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => $spreadOverAddresses ? '10.0.2.1' : '10.0.0.1'])
        ->postJson(route('api.v1.auth.login'), ['email' => $email, 'password' => 'correct-horse-battery', 'device_name' => 'Phone']);

    $response->assertTooManyRequests();
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'from one address' => [LoginRequest::MAX_FAILED_ATTEMPTS, false],
    'from many addresses' => [LoginRequest::MAX_FAILED_ATTEMPTS_PER_ACCOUNT, true],
]);

it('accepts a token until its configured lifetime has passed', function () {
    $token = bearerTokenFor(User::factory()->create());
    $this->travel(479)->minutes();

    $this->withToken($token)->getJson(route('api.v1.me'))->assertOk();
});

it('returns 401 for a token whose lifetime has passed', function () {
    $token = bearerTokenFor(User::factory()->create());
    $this->travel(481)->minutes();

    $response = $this->withToken($token)->getJson(route('api.v1.me'));

    $response->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});
