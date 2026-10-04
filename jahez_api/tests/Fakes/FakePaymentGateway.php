<?php

namespace Tests\Fakes;

use App\Billing\GatewayCheckout;
use App\Billing\GatewayEvent;
use App\Billing\InvalidGatewayCallbackException;
use App\Billing\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A payment gateway adapter for tests only. Like a real adapter, it never reports
 * success when starting a payment, and it trusts a callback only when its HMAC
 * signature matches. Statuses use gateway-style names mapped to PaymentStatus.
 */
final class FakePaymentGateway implements PaymentGateway
{
    public const SECRET = 'fake-gateway-test-secret';

    /**
     * Statuses fetchStatus() reports, by gateway reference.
     *
     * @var array<string, PaymentStatus>
     */
    public static array $statuses = [];

    public static bool $failNextInitiate = false;

    public static int $initiateCalls = 0;

    public static function reset(): void
    {
        self::$statuses = [];
        self::$failNextInitiate = false;
        self::$initiateCalls = 0;
    }

    /**
     * The JSON body of a callback and its signature header value.
     *
     * @param  array<string, string>  $body
     * @return array{string, string}
     */
    public static function signedCallback(array $body): array
    {
        $json = (string) json_encode($body);

        return [$json, hash_hmac('sha256', $json, self::SECRET)];
    }

    public function initiate(Payment $payment): GatewayCheckout
    {
        self::$initiateCalls++;

        if (self::$failNextInitiate) {
            self::$failNextInitiate = false;
            throw new RuntimeException('Fake gateway unavailable.');
        }

        return new GatewayCheckout("fake-{$payment->id}", "https://pay.example.test/checkout/fake-{$payment->id}");
    }

    public function parseCallback(Request $request): GatewayEvent
    {
        $expected = hash_hmac('sha256', $request->getContent(), self::SECRET);

        if (! hash_equals($expected, (string) $request->header('X-Fake-Signature'))) {
            throw new InvalidGatewayCallbackException;
        }

        /** @var array<string, string> $body */
        $body = (array) json_decode($request->getContent(), true);

        return new GatewayEvent(
            $body['event_id'],
            $body['reference'],
            self::status($body['status']),
            $body['amount'],
            $body['currency'],
            $body['failure_reason'] ?? null,
        );
    }

    public function fetchStatus(Payment $payment): GatewayEvent
    {
        $status = self::$statuses[(string) $payment->gateway_reference] ?? PaymentStatus::Pending;

        return new GatewayEvent("status-{$payment->gateway_reference}-{$status->value}", (string) $payment->gateway_reference, $status, $payment->amount, $payment->currency);
    }

    private static function status(string $reported): PaymentStatus
    {
        return match ($reported) {
            'paid' => PaymentStatus::Succeeded,
            'declined' => PaymentStatus::Failed,
            'cancelled' => PaymentStatus::Cancelled,
            'refunded' => PaymentStatus::Refunded,
            default => PaymentStatus::Pending,
        };
    }
}
