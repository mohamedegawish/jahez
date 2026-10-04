<?php

use Illuminate\Support\Facades\Artisan;

/**
 * Puts the application in a production-ready configuration.
 */
function useProductionReadyConfiguration(): void
{
    app()['env'] = 'production';
    config([
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'app.url' => 'https://api.jahez.example',
        'api.frontend_url' => 'https://app.jahez.example',
        'mail.default' => 'smtp',
        'queue.default' => 'database',
        'cors.allowed_origins' => ['https://app.jahez.example'],
        'hashing.driver' => 'bcrypt',
        'hashing.bcrypt.rounds' => 12,
        'database.default' => 'mysql',
        'trustedproxy.proxies' => '10.0.0.0/8',
        'logging.channels.single.level' => 'warning',
        'session.driver' => 'array',
    ]);
}

/**
 * Whether the command's output shows the named check with the given status.
 */
function productionCheckShows(string $output, string $status, string $check): bool
{
    return preg_match('/\|\s*'.$status.'\s*\|\s*'.preg_quote($check, '/').'\s*\|/', $output) === 1;
}

it('passes with a production-ready configuration', function () {
    useProductionReadyConfiguration();

    $exitCode = Artisan::call('app:check-production');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('All production checks passed.')
        ->not->toContain('FAIL');
});

it('fails on each unsafe setting', function (array $unsafeSetting, string $failedCheck) {
    useProductionReadyConfiguration();
    config($unsafeSetting);

    $exitCode = Artisan::call('app:check-production');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and(productionCheckShows($output, 'FAIL', $failedCheck))->toBeTrue()
        ->and($output)->toContain('1 check(s) failed.');
})->with([
    'debug mode on' => [['app.debug' => true], 'APP_DEBUG is off'],
    'missing app key' => [['app.key' => ''], 'APP_KEY is set'],
    'plain HTTP API URL' => [['app.url' => 'http://api.jahez.example'], 'APP_URL uses HTTPS'],
    'plain HTTP front end' => [['api.frontend_url' => 'http://app.jahez.example'], 'FRONTEND_URL uses HTTPS'],
    'log mailer' => [['mail.default' => 'log'], 'Mailer sends real email'],
    'sync queue' => [['queue.default' => 'sync'], 'Queue is asynchronous'],
    'wildcard CORS origin' => [['cors.allowed_origins' => ['*']], 'CORS has no wildcard origin'],
    'Argon2id hash driver' => [['hashing.driver' => 'argon2id'], 'Hash driver is bcrypt'],
    'bcrypt cost out of step' => [['hashing.bcrypt.rounds' => 10], 'BCRYPT_ROUNDS matches the login timing hash'],
    'SQLite database' => [['database.default' => 'sqlite'], 'Database is MySQL'],
    'unknown required provider field' => [['jahez.providers.required_profile_fields' => ['email', 'mail']], 'Required provider fields are known'],
    'unknown factory onboarding field' => [['jahez.factories.required_profile_fields' => ['sectors', 'capital']], 'Factory onboarding fields are known'],
    'documents on the public disk' => [['jahez.documents.disk' => 'public'], 'Organization documents are on a private disk'],
    'documents on an unknown disk' => [['jahez.documents.disk' => 'nowhere'], 'Organization documents are on a private disk'],
    'zero evaluation scale' => [['jahez.providers.evaluation.scale_max' => 0], 'Provider evaluation scale is valid'],
    'pass mark above 100' => [['jahez.providers.evaluation.pass_mark' => '120'], 'Provider evaluation pass mark is valid'],
    'pass mark with three decimals' => [['jahez.providers.evaluation.pass_mark' => '60.125'], 'Provider evaluation pass mark is valid'],
    'unknown business time zone' => [['jahez.financial_policies.timezone' => 'Mars/Olympus'], 'Business time zone is valid'],
    'a financial rule left in the environment' => [['jahez.financial_policies.ignored_environment_settings' => ['JAHEZ_TAX_RATE_PERCENT']], 'No financial rule is set in the environment'],
    'unregistered payment gateway' => [['jahez.billing.payment_gateway' => 'paymob'], 'Payment gateway is registered'],
]);

it('reads the bcrypt cost as the string the environment file provides', function () {
    useProductionReadyConfiguration();
    config(['hashing.bcrypt.rounds' => '12']);

    $exitCode = Artisan::call('app:check-production');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and(productionCheckShows($output, 'PASS', 'BCRYPT_ROUNDS matches the login timing hash'))->toBeTrue();
});

it('warns when every caller is trusted as a proxy', function () {
    useProductionReadyConfiguration();
    config(['trustedproxy.proxies' => '*']);

    $exitCode = Artisan::call('app:check-production');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and(productionCheckShows($output, 'WARN', 'Trusted proxies configured'))->toBeTrue();
});

it('fails outside the production environment', function () {
    useProductionReadyConfiguration();
    app()['env'] = 'local';

    $exitCode = Artisan::call('app:check-production');
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and(productionCheckShows($output, 'FAIL', 'APP_ENV is production'))->toBeTrue();
});

it('only warns about settings that depend on the hosting setup', function () {
    useProductionReadyConfiguration();
    config(['trustedproxy.proxies' => null, 'logging.channels.single.level' => 'debug', 'session.driver' => 'database']);

    $exitCode = Artisan::call('app:check-production');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and(productionCheckShows($output, 'WARN', 'Trusted proxies configured'))->toBeTrue()
        ->and(productionCheckShows($output, 'WARN', 'Log level'))->toBeTrue()
        ->and(productionCheckShows($output, 'WARN', 'Sessions are not stored'))->toBeTrue();
});
