<?php

namespace App\Console\Commands;

use App\Billing\PaymentGatewayManager;
use App\Billing\PaymentProcessor;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Console\Command;
use Throwable;

/**
 * Asks the configured gateway for the status of payments still pending after a while
 * and applies what it reports, as a callback would (ADR-017). Covers lost callbacks.
 */
class ReconcilePayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:reconcile {--older-than=15 : Minutes a payment must have been pending}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Query the payment gateway for payments still pending and apply their status';

    public function handle(): int
    {
        if (! PaymentGatewayManager::isConfigured()) {
            $this->components->info('No payment gateway is configured (OQ-16); nothing to reconcile.');

            return self::SUCCESS;
        }

        $gateway = PaymentGatewayManager::gateway();
        $gatewayKey = (string) PaymentGatewayManager::configuredKey();
        $outcomes = [];

        Payment::query()
            ->where('gateway', $gatewayKey)
            ->where('status', PaymentStatus::Pending)
            ->whereNotNull('gateway_reference')
            ->where('created_at', '<=', now()->subMinutes((int) $this->option('older-than')))
            ->orderBy('id')
            ->each(function (Payment $payment) use ($gateway, $gatewayKey, &$outcomes): void {
                try {
                    $outcome = PaymentProcessor::apply($gateway->fetchStatus($payment), $gatewayKey, PaymentProcessor::SOURCE_RECONCILIATION);
                } catch (Throwable $exception) {
                    report($exception);
                    $outcome = 'gateway_error';
                }
                $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
            });

        $this->table(['Outcome', 'Payments'], collect($outcomes)->map(fn (int $count, string $outcome): array => [$outcome, $count])->values()->all());

        return self::SUCCESS;
    }
}
