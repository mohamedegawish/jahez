<?php

use App\Billing\InvoiceCalculator;
use App\Billing\Money;
use Carbon\CarbonImmutable;

$vat = fn (string $rate = '14'): array => ['code' => 'vat', 'name_ar' => 'ضريبة القيمة المضافة', 'rate_percent' => $rate];

it('adds an exclusive tax to the subtotal, half up to the piastre', function () use ($vat) {
    $result = InvoiceCalculator::calculate(Money::toMinor('1000.05'), ['prices_include_tax' => false, 'taxes' => [$vat()], 'fees' => []], null);

    expect($result['net_service_amount'])->toBe('1000.05')
        ->and($result['taxes'][0]['amount'])->toBe('140.01')
        ->and($result['tax_total'])->toBe('140.01')
        ->and($result['tax_rate_percent'])->toBe('14.00')
        ->and($result['total'])->toBe('1140.06');
});

it('extracts an inclusive tax from the subtotal, so the total is the subtotal', function () use ($vat) {
    $result = InvoiceCalculator::calculate(Money::toMinor('1140.06'), ['prices_include_tax' => true, 'taxes' => [$vat()], 'fees' => []], null);

    expect($result['net_service_amount'])->toBe('1000.05')
        ->and($result['tax_total'])->toBe('140.01')
        ->and($result['total'])->toBe('1140.06');
});

it('splits inclusive taxes by rate, the last one taking the rounding remainder, so the parts add up exactly', function () {
    $taxes = [
        ['code' => 'a', 'name_ar' => 'أ', 'rate_percent' => '10'],
        ['code' => 'b', 'name_ar' => 'ب', 'rate_percent' => '5'],
    ];

    $result = InvoiceCalculator::calculate(Money::toMinor('1000.00'), ['prices_include_tax' => true, 'taxes' => $taxes, 'fees' => []], null);

    expect($result['net_service_amount'])->toBe('869.57')
        ->and(array_column($result['taxes'], 'amount'))->toBe(['86.96', '43.47'])
        ->and($result['tax_total'])->toBe('130.43')
        ->and($result['total'])->toBe('1000.00')
        ->and(Money::toMinor($result['net_service_amount']) + Money::toMinor($result['tax_total']))->toBe(Money::toMinor($result['total']));
});

it('adds fixed and percentage fees, taxing only the taxable ones', function () use ($vat) {
    $fees = [
        ['code' => 'platform', 'name_ar' => 'رسم المنصة', 'calculation' => 'fixed', 'amount' => '50.00', 'rate_percent' => null, 'taxable' => true],
        ['code' => 'service', 'name_ar' => 'رسم خدمة', 'calculation' => 'percentage', 'amount' => null, 'rate_percent' => '2.00', 'taxable' => false],
    ];

    $result = InvoiceCalculator::calculate(Money::toMinor('1000.00'), ['prices_include_tax' => false, 'taxes' => [$vat()], 'fees' => $fees], null);

    expect(array_column($result['fees'], 'amount'))->toBe(['50.00', '20.00'])
        ->and($result['fees_total'])->toBe('70.00')
        ->and($result['taxes'][0]['base'])->toBe('1050.00')
        ->and($result['tax_total'])->toBe('147.00')
        ->and($result['total'])->toBe('1217.00');
});

it('takes the revenue share of the net service amount, before tax and fees', function (bool $inclusive, string $subtotal, string $share) use ($vat) {
    $result = InvoiceCalculator::calculate(Money::toMinor($subtotal), ['prices_include_tax' => $inclusive, 'taxes' => [$vat()], 'fees' => []], ['rate_percent' => '20.00']);

    expect($result['revenue_share'])->toBe(['rate_percent' => '20.00', 'base' => '1000.05', 'amount' => $share]);
})->with([
    'exclusive prices' => [false, '1000.05', '200.01'],
    'inclusive prices' => [true, '1140.06', '200.01'],
]);

it('rounds half up at the piastre boundary and calculates no tax for an empty tax list', function () use ($vat) {
    expect(InvoiceCalculator::calculate(5, ['prices_include_tax' => false, 'taxes' => [$vat('10')], 'fees' => []], null)['tax_total'])->toBe('0.01')
        ->and(InvoiceCalculator::calculate(4, ['prices_include_tax' => false, 'taxes' => [$vat('10')], 'fees' => []], null)['tax_total'])->toBe('0.00');

    $none = InvoiceCalculator::calculate(Money::toMinor('99.99'), ['prices_include_tax' => false, 'taxes' => [], 'fees' => []], null);

    expect($none['tax_total'])->toBe('0.00')->and($none['tax_rate_percent'])->toBe('0.00')->and($none['total'])->toBe('99.99')->and($none['revenue_share'])->toBeNull();
});

it('keeps exact results for the largest subtotals, with no floating point', function () use ($vat) {
    $result = InvoiceCalculator::calculate(Money::toMinor('800000000000.01'), ['prices_include_tax' => false, 'taxes' => [$vat('12.5')], 'fees' => []], ['rate_percent' => '33.33']);

    expect($result['tax_total'])->toBe('100000000000.00')
        ->and($result['total'])->toBe('900000000000.01')
        ->and($result['revenue_share']['amount'])->toBe('266640000000.00');
});

it('counts the due date in calendar days after the issue day', function () {
    expect(InvoiceCalculator::dueDate(CarbonImmutable::parse('2026-01-31'), ['due_rule' => 'days_after_issue', 'due_days' => 30])->toDateString())->toBe('2026-03-02')
        ->and(InvoiceCalculator::dueDate(CarbonImmutable::parse('2026-10-05'), ['due_rule' => 'days_after_issue', 'due_days' => 0])->toDateString())->toBe('2026-10-05');
});
