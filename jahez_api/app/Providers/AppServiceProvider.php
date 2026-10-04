<?php

namespace App\Providers;

use App\Models\User;
use App\Rules\MaxBytes;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->configureRateLimiting();
        $this->configureAuthentication();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(Config::integer('api.rate_limit_per_minute'))
                ->by($user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip());
        });

        RateLimiter::for('password-reset', fn (Request $request): Limit => Limit::perMinute(5)->by('ip:'.$request->ip()));

        // Public self-registration (ADR-019): provisional abuse limit per IP (OQ-33).
        RateLimiter::for('registration', fn (Request $request): Limit => Limit::perHour(Config::integer('jahez.registration.per_hour_per_ip'))->by('ip:'.$request->ip()));

        // Negotiation writes per user (technical defaults, not business rules; ADR-015).
        RateLimiter::for('negotiation-messages', fn (Request $request): Limit => Limit::perMinute(30)->by('user:'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('negotiation-offers', fn (Request $request): Limit => Limit::perMinute(10)->by('user:'.$request->user()?->getAuthIdentifier()));
    }

    /**
     * Password policy, reset links and token validity (ADR-003, ADR-011).
     */
    private function configureAuthentication(): void
    {
        Password::defaults(fn (): Password => Password::min(12)->rules([new MaxBytes(72)]));

        ResetPassword::createUrlUsing(fn (User $user, string $token): string => rtrim(Config::string('api.frontend_url'), '/')
            .'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]));

        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $accessToken, bool $isValid): bool => $isValid
                && $accessToken->tokenable instanceof User
                && $accessToken->tokenable->isActive(),
        );
    }
}
