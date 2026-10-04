<?php

use App\Models\ProviderRequest;
use App\Models\ServiceProvider;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The acting user for a matrix row, relative to the marketplace scenario.
 *
 * @param  array{factoryMember: User, serviceRequest: ServiceRequest, threads: list<ProviderRequest>, providerMembers: list<User>}  $scenario
 */
function serviceRequestPolicyActor(string $actor, array $scenario): User
{
    return match ($actor) {
        'imc admin' => User::factory()->imcAdmin()->create(),
        'requesting factory member' => $scenario['factoryMember'],
        'member of another factory' => User::factory()->factoryMember()->create(),
        'provider that received it' => $scenario['providerMembers'][0],
        'provider that did not' => User::factory()->providerMember(ServiceProvider::factory()->approved()->create())->create(),
    };
}

test('record decisions for each actor on a service request', function (string $actor, string $ability, string $expectedDecision) {
    $scenario = marketplaceRequest(1);

    $response = Gate::forUser(serviceRequestPolicyActor($actor, $scenario))->inspect($ability, $scenario['serviceRequest']);

    expect($response->allowed() ? 'allow' : 'deny '.($response->status() ?? 403))->toBe($expectedDecision);
})->with([
    ['imc admin', 'view', 'allow'],
    ['requesting factory member', 'view', 'allow'],
    ['provider that received it', 'view', 'allow'],
    ['member of another factory', 'view', 'deny 404'],
    ['provider that did not', 'view', 'deny 404'],
    ['requesting factory member', 'cancel', 'allow'],
    ['imc admin', 'cancel', 'deny 403'],
    ['provider that received it', 'cancel', 'deny 403'],
    ['member of another factory', 'cancel', 'deny 404'],
    ['provider that did not', 'cancel', 'deny 404'],
    ['requesting factory member', 'addProviders', 'allow'],
    ['imc admin', 'addProviders', 'deny 403'],
    ['provider that received it', 'addProviders', 'deny 403'],
    ['member of another factory', 'addProviders', 'deny 404'],
    ['provider that did not', 'addProviders', 'deny 404'],
]);

test('only factory members create requests; providers list through their inbox', function (string $actor, bool $canCreate, bool $canList) {
    $user = serviceRequestPolicyActor($actor, marketplaceRequest(1));

    expect(Gate::forUser($user)->allows('create', ServiceRequest::class))->toBe($canCreate)
        ->and(Gate::forUser($user)->allows('viewAny', ServiceRequest::class))->toBe($canList);
})->with([
    ['requesting factory member', true, true],
    ['imc admin', false, true],
    ['provider that received it', false, false],
]);
