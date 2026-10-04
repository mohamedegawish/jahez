<?php

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The acting user for a matrix row, relative to a draft invoice's marketplace.
 *
 * @param  array{factoryMember: User, providerMembers: list<User>}  $scenario
 */
function invoicePolicyActor(string $actor, array $scenario): User
{
    return match ($actor) {
        'imc admin' => User::factory()->imcAdmin()->create(),
        'factory party' => $scenario['factoryMember'],
        'provider party' => $scenario['providerMembers'][0],
        'competing provider' => $scenario['providerMembers'][1],
        'another factory' => User::factory()->factoryMember()->create(),
    };
}

test('record decisions for each actor on an invoice', function (string $issuer, string $actor, string $ability, string $expectedDecision) {
    configureBilling();
    $scenario = draftInvoice();
    $invoice = $scenario['invoice'];
    if ($issuer === 'imc') {
        Invoice::query()->whereKey($invoice->id)->update(['issuer' => 'imc']);
        $invoice->refresh();
    }

    $response = Gate::forUser(invoicePolicyActor($actor, $scenario))->inspect($ability, $invoice);

    expect($response->allowed() ? 'allow' : 'deny '.($response->status() ?? 403))->toBe($expectedDecision);
})->with([
    ['service_provider', 'factory party', 'view', 'allow'],
    ['service_provider', 'provider party', 'view', 'allow'],
    ['service_provider', 'imc admin', 'view', 'allow'],
    ['service_provider', 'competing provider', 'view', 'deny 404'],
    ['service_provider', 'another factory', 'view', 'deny 404'],
    ['service_provider', 'provider party', 'manage', 'allow'],
    ['service_provider', 'factory party', 'manage', 'deny 403'],
    ['service_provider', 'imc admin', 'manage', 'deny 403'],
    ['service_provider', 'competing provider', 'manage', 'deny 404'],
    ['imc', 'imc admin', 'manage', 'allow'],
    ['imc', 'provider party', 'manage', 'deny 403'],
    ['service_provider', 'factory party', 'pay', 'allow'],
    ['service_provider', 'provider party', 'pay', 'deny 403'],
    ['service_provider', 'imc admin', 'pay', 'deny 403'],
    ['service_provider', 'another factory', 'pay', 'deny 404'],
]);
