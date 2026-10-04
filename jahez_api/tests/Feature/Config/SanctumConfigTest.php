<?php

/**
 * Evaluates config/sanctum.php as if SANCTUM_EXPIRATION had the given value
 * (null = not set), restoring the environment afterwards.
 */
function sanctumExpirationWhenEnvIs(?string $value): mixed
{
    $previousServerValue = $_SERVER['SANCTUM_EXPIRATION'] ?? null;
    $previousEnvValue = $_ENV['SANCTUM_EXPIRATION'] ?? null;
    $previousPutenvValue = getenv('SANCTUM_EXPIRATION');

    unset($_SERVER['SANCTUM_EXPIRATION'], $_ENV['SANCTUM_EXPIRATION']);
    putenv('SANCTUM_EXPIRATION');

    if ($value !== null) {
        $_SERVER['SANCTUM_EXPIRATION'] = $_ENV['SANCTUM_EXPIRATION'] = $value;
        putenv("SANCTUM_EXPIRATION={$value}");
    }

    try {
        return (require base_path('config/sanctum.php'))['expiration'];
    } finally {
        unset($_SERVER['SANCTUM_EXPIRATION'], $_ENV['SANCTUM_EXPIRATION']);
        putenv('SANCTUM_EXPIRATION');

        if ($previousServerValue !== null) {
            $_SERVER['SANCTUM_EXPIRATION'] = $previousServerValue;
        }
        if ($previousEnvValue !== null) {
            $_ENV['SANCTUM_EXPIRATION'] = $previousEnvValue;
        }
        if ($previousPutenvValue !== false) {
            putenv("SANCTUM_EXPIRATION={$previousPutenvValue}");
        }
    }
}

it('uses the configured token lifetime in minutes', function () {
    expect(sanctumExpirationWhenEnvIs('120'))->toBe(120);
});

it('falls back to 8 hours instead of issuing already-expired tokens', function (?string $value) {
    expect(sanctumExpirationWhenEnvIs($value))->toBe(480);
})->with([
    'not set' => [null],
    'blank' => [''],
    'zero' => ['0'],
    'the word null' => ['null'],
]);

/*
 * Bearer tokens only (ADR-003): Sanctum's CSRF-cookie route serves cookie
 * authentication, which is disabled, and it would start a session for every caller.
 */
it('registers no CSRF cookie route and so starts no session for it', function () {
    config(['session.driver' => 'database']);

    $response = $this->get('/sanctum/csrf-cookie');

    $response->assertNotFound()->assertCookieMissing('XSRF-TOKEN');
    $this->assertDatabaseCount('sessions', 0);
});
