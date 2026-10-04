<?php

namespace App\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Calculates an invoice from its line subtotal and the approved tax and revenue-share
 * policy values (ADR-023). Integer minor units only (App\Billing\Money); every rounding
 * is half up to the piastre, once per component, and the parts always add up to the
 * total exactly.
 *
 * - Prices exclusive of tax: each tax is its rate of the taxable base (the subtotal plus
 *   taxable fees).
 * - Prices inclusive of tax: the subtotal already contains the taxes. The net amount is
 *   extracted once (subtotal × 100 / (100 + the sum of the rates)), the difference is the
 *   tax on the lines, split by rate with the last tax taking the rounding remainder.
 *   Fees are always added on top, and a taxable fee is taxed on top.
 * - Fees: a fixed amount, or a rate of the net service amount.
 * - Revenue share: its rate of the net service amount (the subtotal before tax),
 *   informational only.
 */
final class InvoiceCalculator
{
    /**
     * @param  array<string, mixed>  $tax  approved `tax` policy parameters
     * @param  array<string, mixed>|null  $revenueShare  approved `revenue_share` parameters, if any applies
     * @return array{
     *     subtotal: string, prices_include_tax: bool, net_service_amount: string,
     *     fees: list<array{code: string, name_ar: string, calculation: string, rate_percent: string|null, amount: string, taxable: bool}>,
     *     fees_total: string,
     *     taxes: list<array{code: string, name_ar: string, rate_percent: string, base: string, amount: string}>,
     *     tax_rate_percent: string, tax_total: string, total: string,
     *     revenue_share: array{rate_percent: string, base: string, amount: string}|null
     * }
     */
    public static function calculate(int $subtotalMinor, array $tax, ?array $revenueShare): array
    {
        $taxes = array_values(array_map(fn (array $item): array => [...$item, 'bp' => (int) Money::basisPoints($item['rate_percent'])], $tax['taxes'] ?? []));
        $totalBasisPoints = array_sum(array_column($taxes, 'bp'));
        $inclusive = (bool) ($tax['prices_include_tax'] ?? false);

        $netMinor = $inclusive && $totalBasisPoints > 0
            ? self::divideHalfUp($subtotalMinor * 10_000, 10_000 + $totalBasisPoints)
            : $subtotalMinor;
        $lineTaxMinor = $subtotalMinor - $netMinor;

        $fees = [];
        $taxableFeesMinor = 0;
        foreach ($tax['fees'] ?? [] as $fee) {
            $amountMinor = $fee['calculation'] === 'fixed'
                ? Money::toMinor((string) $fee['amount'])
                : Money::percentOf($netMinor, (int) Money::basisPoints($fee['rate_percent']));
            $fees[] = [
                'code' => (string) $fee['code'],
                'name_ar' => (string) $fee['name_ar'],
                'calculation' => (string) $fee['calculation'],
                'rate_percent' => $fee['calculation'] === 'percentage' ? (string) $fee['rate_percent'] : null,
                'amount' => $amountMinor,
                'taxable' => (bool) $fee['taxable'],
            ];
            if ($fee['taxable']) {
                $taxableFeesMinor += $amountMinor;
            }
        }
        $feesMinor = array_sum(array_column($fees, 'amount'));

        $taxLines = [];
        $allocatedLineTax = 0;
        foreach ($taxes as $index => $item) {
            if ($inclusive) {
                $onLines = $index === array_key_last($taxes)
                    ? $lineTaxMinor - $allocatedLineTax
                    : Money::percentOf($netMinor, $item['bp']);
                $allocatedLineTax += $onLines;
                $base = $netMinor + $taxableFeesMinor;
                $amount = $onLines + Money::percentOf($taxableFeesMinor, $item['bp']);
            } else {
                $base = $netMinor + $taxableFeesMinor;
                $amount = Money::percentOf($base, $item['bp']);
            }
            $taxLines[] = ['code' => (string) $item['code'], 'name_ar' => (string) $item['name_ar'], 'rate_percent' => (string) $item['rate_percent'], 'base' => $base, 'amount' => $amount];
        }
        $taxMinor = array_sum(array_column($taxLines, 'amount'));
        $totalMinor = $netMinor + $feesMinor + $taxMinor;

        if ($totalMinor > Money::MAX_MINOR) {
            throw ValidationException::withMessages(['lines' => 'The invoice total would exceed the largest amount the system can hold.']);
        }

        $share = null;
        if ($revenueShare !== null) {
            $shareBasisPoints = (int) Money::basisPoints($revenueShare['rate_percent']);
            $share = ['rate_percent' => (string) $revenueShare['rate_percent'], 'base' => Money::fromMinor($netMinor), 'amount' => Money::fromMinor(Money::percentOf($netMinor, $shareBasisPoints))];
        }

        return [
            'subtotal' => Money::fromMinor($subtotalMinor),
            'prices_include_tax' => $inclusive,
            'net_service_amount' => Money::fromMinor($netMinor),
            'fees' => array_map(fn (array $fee): array => [...$fee, 'amount' => Money::fromMinor($fee['amount'])], $fees),
            'fees_total' => Money::fromMinor($feesMinor),
            'taxes' => array_map(fn (array $line): array => [...$line, 'base' => Money::fromMinor($line['base']), 'amount' => Money::fromMinor($line['amount'])], $taxLines),
            'tax_rate_percent' => Money::fromMinor($totalBasisPoints),
            'tax_total' => Money::fromMinor($taxMinor),
            'total' => Money::fromMinor($totalMinor),
            'revenue_share' => $share,
        ];
    }

    /**
     * Calculates a due date from approved payment terms.
     *
     * @param  array<string, mixed>  $paymentTerms
     */
    public static function dueDate(CarbonImmutable $issuedOn, array $paymentTerms): CarbonImmutable
    {
        return match ($paymentTerms['due_rule']) {
            'days_after_issue' => $issuedOn->addDays((int) $paymentTerms['due_days']),
            default => throw new \LogicException("Unknown due rule {$paymentTerms['due_rule']}."),
        };
    }

    private static function divideHalfUp(int $numerator, int $denominator): int
    {
        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
