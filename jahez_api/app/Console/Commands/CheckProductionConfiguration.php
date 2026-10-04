<?php

namespace App\Console\Commands;

use App\Billing\PaymentGatewayManager;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\StoreProviderEvaluationRequest;
use App\Models\Factory;
use App\Models\ServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Go-live gate (Phase 9): checks the loaded configuration for settings that are unsafe
 * in production. Run it on the production host after `php artisan config:cache`.
 * Exits non-zero when any check fails; warnings do not fail the command.
 */
class CheckProductionConfiguration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-production';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the configuration for settings that are unsafe in production';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rows = [];
        $failures = 0;

        foreach ($this->checks() as [$name, $status, $detail]) {
            $rows[] = [$status, $name, $detail];
            $failures += $status === 'FAIL' ? 1 : 0;
        }

        $this->table(['Status', 'Check', 'Detail'], $rows);

        if ($failures > 0) {
            $this->components->error("{$failures} check(s) failed. Fix them before going live.");

            return self::FAILURE;
        }

        $this->components->info('All production checks passed.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function checks(): array
    {
        $mailer = Config::string('mail.default');
        $queue = Config::string('queue.default');
        $origins = (array) Config::get('cors.allowed_origins', []);

        return [
            $this->check('APP_ENV is production', app()->isProduction(), 'APP_ENV='.app()->environment()),
            $this->check('APP_DEBUG is off', Config::get('app.debug') === false, 'Debug mode exposes exception details and enables Laravel Boost routes'),
            $this->check('APP_KEY is set', filled(Config::get('app.key')), 'Generate with php artisan key:generate'),
            $this->check('APP_URL uses HTTPS', Str::startsWith(Config::string('app.url'), 'https://'), Config::string('app.url')),
            $this->check('FRONTEND_URL uses HTTPS', Str::startsWith(Config::string('api.frontend_url'), 'https://'), 'Reset and invitation links point here'),
            $this->check('Mailer sends real email', ! in_array($mailer, ['log', 'array'], true), "MAIL_MAILER={$mailer}; the log mailer writes reset tokens to the log"),
            $this->check('Queue is asynchronous', $queue !== 'sync', "QUEUE_CONNECTION={$queue}; run php artisan queue:work"),
            $this->check('CORS has no wildcard origin', ! in_array('*', $origins, true), 'CORS_ALLOWED_ORIGINS must list exact origins'),
            $this->check(
                'Hash driver is bcrypt',
                Config::get('hashing.driver') === 'bcrypt',
                'The login timing hash is bcrypt; with another HASH_DRIVER an unknown email fails with a server error, which reveals which accounts exist'
            ),
            $this->check(
                'BCRYPT_ROUNDS matches the login timing hash',
                filter_var(Config::get('hashing.bcrypt.rounds'), FILTER_VALIDATE_INT) === LoginRequest::UNMATCHABLE_PASSWORD_HASH_COST,
                'Must be '.LoginRequest::UNMATCHABLE_PASSWORD_HASH_COST.' so unknown and known emails take equal time'
            ),
            $this->check('Database is MySQL', Config::string('database.default') === 'mysql', 'DB_CONNECTION='.Config::string('database.default')),
            $this->check(
                'Required provider fields are known',
                array_diff((array) Config::get('jahez.providers.required_profile_fields', []), ServiceProvider::REQUIRABLE_FIELDS) === [],
                'JAHEZ_PROVIDER_REQUIRED_FIELDS may list only: '.implode(', ', ServiceProvider::REQUIRABLE_FIELDS).' (OQ-36)'
            ),
            $this->check(
                'Factory onboarding fields are known',
                array_diff((array) Config::get('jahez.factories.required_profile_fields', []), [...Factory::PROFILE_FIELDS, 'sectors']) === [],
                'JAHEZ_FACTORY_REQUIRED_FIELDS may list only: '.implode(', ', [...Factory::PROFILE_FIELDS, 'sectors']).' (OQ-19)'
            ),
            $this->check(
                'Organization documents are on a private disk',
                Config::get('filesystems.disks.'.Config::string('jahez.documents.disk').'.visibility') !== 'public'
                    && Config::get('filesystems.disks.'.Config::string('jahez.documents.disk').'.serve') !== true
                    && is_array(Config::get('filesystems.disks.'.Config::string('jahez.documents.disk'))),
                'JAHEZ_DOCUMENTS_DISK='.Config::string('jahez.documents.disk').' must name a configured disk that is neither public nor served; registration documents hold tax and commercial registry data (ADR-019)'
            ),
            $this->check(
                'Provider evaluation scale is valid',
                Config::get('jahez.providers.evaluation.scale_max') === null || StoreProviderEvaluationRequest::scaleMax() !== null,
                'JAHEZ_PROVIDER_EVALUATION_SCALE_MAX must be a positive whole number, or unset while OQ-13 is open'
            ),
            $this->check(
                'Business time zone is valid',
                in_array(Config::get('jahez.financial_policies.timezone'), timezone_identifiers_list(), true),
                'JAHEZ_BUSINESS_TIMEZONE must be a time zone identifier such as Africa/Cairo; financial policy effective dates are read in it (ADR-023)'
            ),
            $this->check(
                'No financial rule is set in the environment',
                Config::get('jahez.financial_policies.ignored_environment_settings') === [],
                implode(', ', (array) Config::get('jahez.financial_policies.ignored_environment_settings')).' no longer take effect: these rules are approved policies in the database (ADR-023). Remove them'
            ),
            $this->check(
                'Payment gateway is registered',
                Config::get('jahez.billing.payment_gateway') === null || PaymentGatewayManager::isConfigured(),
                'JAHEZ_PAYMENT_GATEWAY must name an adapter registered in config jahez.billing.gateways, or be unset while OQ-16 is open'
            ),
            $this->check(
                'Provider evaluation pass mark is valid',
                Config::get('jahez.providers.evaluation.pass_mark') === null || StoreProviderEvaluationRequest::passMark() !== null,
                'JAHEZ_PROVIDER_EVALUATION_PASS_MARK must be a mark from 0 to 100 with at most two decimals, or unset while OQ-13 is open'
            ),
            [
                'Trusted proxies configured',
                in_array(Config::get('trustedproxy.proxies'), [null, '*', '**'], true) ? 'WARN' : 'PASS',
                'List the load balancer addresses in TRUSTED_PROXIES: unset, every guest shares one rate limit; "*" is safe only if clients cannot reach the app except through the proxy, or they can forge their IP address',
            ],
            [
                'Log level',
                Config::get('logging.channels.single.level') === 'debug' ? 'WARN' : 'PASS',
                'LOG_LEVEL=debug is verbose for production',
            ],
            [
                'Sessions are not stored',
                Config::get('session.driver') === 'array' ? 'PASS' : 'WARN',
                'The API uses no sessions; SESSION_DRIVER=array stops the web routes writing a session for every visitor',
            ],
        ];
    }

    /**
     * @return array{string, string, string}
     */
    private function check(string $name, bool $passes, string $detail): array
    {
        return [$name, $passes ? 'PASS' : 'FAIL', $detail];
    }
}
