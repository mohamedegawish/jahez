<?php

use App\Billing\Money;

it('converts decimal strings to minor units and back exactly', function (string $amount, int $minor, string $formatted) {
    expect(Money::toMinor($amount))->toBe($minor)
        ->and(Money::fromMinor($minor))->toBe($formatted);
})->with([
    ['0', 0, '0.00'],
    ['0.5', 50, '0.50'],
    ['0.05', 5, '0.05'],
    ['1234.10', 123410, '1234.10'],
    ['999999999999.99', Money::MAX_MINOR, '999999999999.99'],
]);

it('rejects anything but a non-negative amount with at most two decimals', function (string $amount) {
    expect(fn () => Money::toMinor($amount))->toThrow(InvalidArgumentException::class);
})->with(['-1', '1.005', 'ten', '', '1e3', '1000000000000']);

it('takes a percentage of an amount, rounding half up to the minor unit', function (int $minor, int $basisPoints, int $expected) {
    expect(Money::percentOf($minor, $basisPoints))->toBe($expected);
})->with([
    '14% of 1000.05 is 140.007, so 140.01' => [100005, 1400, 14001],
    '10% of 0.05 is exactly half a piastre, so up' => [5, 1000, 1],
    '12.5% of 0.03 is 0.00375, so 0.00' => [3, 1250, 0],
    '0% is nothing' => [100005, 0, 0],
    '100% is the amount' => [Money::MAX_MINOR, 10000, Money::MAX_MINOR],
]);

it('reads a percentage from 0 to 100 with at most two decimals as basis points', function (mixed $percent, ?int $basisPoints) {
    expect(Money::basisPoints($percent))->toBe($basisPoints);
})->with([
    ['14', 1400],
    ['12.5', 1250],
    ['0', 0],
    [14, 1400],
    ['100', 10000],
    ['100.01', null],
    ['14.125', null],
    ['-1', null],
    ['fourteen', null],
    [null, null],
]);
