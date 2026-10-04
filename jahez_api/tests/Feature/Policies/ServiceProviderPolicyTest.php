<?php

use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Creates the acting user for a matrix row, relative to the provider under test.
 */
function serviceProviderPolicyActor(string $actor, ServiceProvider $serviceProvider): User
{
    return match ($actor) {
        'imc admin' => User::factory()->imcAdmin()->create(),
        'member of the provider' => User::factory()->providerMember($serviceProvider)->create(),
        'member of another provider' => User::factory()->providerMember()->create(),
        'factory member' => User::factory()->factoryMember()->create(),
    };
}

test('record decisions for each actor', function (string $actor, string $ability, string $expectedDecision) {
    $serviceProvider = ServiceProvider::factory()->create();

    $response = Gate::forUser(serviceProviderPolicyActor($actor, $serviceProvider))->inspect($ability, $serviceProvider);

    expect($response->allowed() ? 'allow' : 'deny '.($response->status() ?? 403))->toBe($expectedDecision);
})->with([
    ['imc admin', 'view', 'allow'],
    ['member of the provider', 'view', 'allow'],
    ['member of another provider', 'view', 'deny 404'],
    ['factory member', 'view', 'deny 404'],
    ['imc admin', 'update', 'allow'],
    ['member of the provider', 'update', 'allow'],
    ['member of another provider', 'update', 'deny 404'],
    ['factory member', 'update', 'deny 404'],
    ['imc admin', 'approve', 'allow'],
    ['member of the provider', 'approve', 'deny 403'],
    ['member of another provider', 'approve', 'deny 404'],
    ['factory member', 'approve', 'deny 404'],
    ['imc admin', 'reviewListing', 'allow'],
    ['member of the provider', 'reviewListing', 'deny 403'],
    ['member of another provider', 'reviewListing', 'deny 404'],
    ['factory member', 'reviewListing', 'deny 404'],
    ['member of the provider', 'resubmitListing', 'allow'],
    ['imc admin', 'resubmitListing', 'deny 403'],
    ['member of another provider', 'resubmitListing', 'deny 404'],
    ['factory member', 'resubmitListing', 'deny 404'],
    ['imc admin', 'requestReview', 'deny 403'],
    ['member of the provider', 'requestReview', 'allow'],
    ['member of another provider', 'requestReview', 'deny 404'],
    ['factory member', 'requestReview', 'deny 404'],
    ['imc admin', 'evaluate', 'allow'],
    ['member of the provider', 'evaluate', 'deny 403'],
    ['member of another provider', 'evaluate', 'deny 404'],
    ['factory member', 'evaluate', 'deny 404'],
    ['imc admin', 'requestLegalChange', 'deny 403'],
    ['member of the provider', 'requestLegalChange', 'allow'],
    ['member of another provider', 'requestLegalChange', 'deny 404'],
    ['factory member', 'requestLegalChange', 'deny 404'],
]);

test('only IMC administrators who decide approvals may read the change request queue', function (string $actor, bool $allowed) {
    $user = serviceProviderPolicyActor($actor, ServiceProvider::factory()->create());

    expect(Gate::forUser($user)->allows('reviewChangeRequests', ServiceProvider::class))->toBe($allowed);
})->with([
    ['imc admin', true],
    ['member of the provider', false],
    ['factory member', false],
]);

test('factory members and IMC administrators may browse the provider directory, provider members may not', function (string $actor, bool $allowed) {
    $user = serviceProviderPolicyActor($actor, ServiceProvider::factory()->create());

    expect(Gate::forUser($user)->allows('viewDirectory', ServiceProvider::class))->toBe($allowed);
})->with([
    ['imc admin', true],
    ['factory member', true],
    ['member of the provider', false],
]);

test('only IMC administrators may list and create service providers', function (string $actor, bool $allowed) {
    $user = serviceProviderPolicyActor($actor, ServiceProvider::factory()->create());

    expect(Gate::forUser($user)->allows('viewAny', ServiceProvider::class))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('create', ServiceProvider::class))->toBe($allowed);
})->with([
    ['imc admin', true],
    ['member of the provider', false],
    ['factory member', false],
]);
