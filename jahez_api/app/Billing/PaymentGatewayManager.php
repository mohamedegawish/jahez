<?php

namespace App\Billing;

/**
 * Resolves the configured payment gateway adapter (config jahez.billing.payment_gateway
 * and jahez.billing.gateways). With none configured, payments cannot start (409
 * policy_not_configured) and every callback URL is 404.
 */
final class PaymentGatewayManager
{
    public static function isConfigured(): bool
    {
        return self::configuredClass() !== null;
    }

    public static function configuredKey(): ?string
    {
        return self::isConfigured() ? (string) config('jahez.billing.payment_gateway') : null;
    }

    public static function gateway(): PaymentGateway
    {
        $class = self::configuredClass()
            ?? throw new PolicyNotConfiguredException('No payment gateway is configured, so payments cannot be started.', 'OQ-16');

        return app($class);
    }

    /**
     * The configured gateway when the key names it, or null for any other key.
     */
    public static function forKey(string $key): ?PaymentGateway
    {
        return self::configuredKey() === $key ? self::gateway() : null;
    }

    /**
     * @return class-string<PaymentGateway>|null
     */
    private static function configuredClass(): ?string
    {
        $key = config('jahez.billing.payment_gateway');
        $class = is_string($key) && $key !== '' ? config("jahez.billing.gateways.{$key}") : null;

        return is_string($class) && is_subclass_of($class, PaymentGateway::class) ? $class : null;
    }
}
