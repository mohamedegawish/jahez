<?php

use App\Models\Factory;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Creates the acting user for a matrix row, relative to the factory under test.
 */
function factoryPolicyActor(string $actor, Factory $factory): User
{
    return match ($actor) {
        'imc admin' => User::factory()->imcAdmin()->create(),
        'member of the factory' => User::factory()->factoryMember($factory)->create(),
        'member of another factory' => User::factory()->factoryMember()->create(),
        'provider member' => User::factory()->providerMember()->create(),
    };
}

test('record decisions for each actor', function (string $actor, string $ability, string $expectedDecision) {
    $factory = Factory::factory()->create();

    $response = Gate::forUser(factoryPolicyActor($actor, $factory))->inspect($ability, $factory);

    expect($response->allowed() ? 'allow' : 'deny '.($response->status() ?? 403))->toBe($expectedDecision);
})->with([
    ['imc admin', 'view', 'allow'],
    ['member of the factory', 'view', 'allow'],
    ['member of another factory', 'view', 'deny 404'],
    ['provider member', 'view', 'deny 404'],
    ['imc admin', 'update', 'allow'],
    ['member of the factory', 'update', 'allow'],
    ['member of another factory', 'update', 'deny 404'],
    ['provider member', 'update', 'deny 404'],
    ['member of the factory', 'requestLegalChange', 'allow'],
    ['imc admin', 'requestLegalChange', 'deny 403'],
    ['member of another factory', 'requestLegalChange', 'deny 404'],
    ['provider member', 'requestLegalChange', 'deny 404'],
    ['imc admin', 'reviewLegalChange', 'allow'],
    ['member of the factory', 'reviewLegalChange', 'deny 403'],
    ['member of another factory', 'reviewLegalChange', 'deny 404'],
    ['provider member', 'reviewLegalChange', 'deny 404'],
    ['imc admin', 'approve', 'allow'],
    ['member of the factory', 'approve', 'deny 403'],
    ['member of another factory', 'approve', 'deny 404'],
    ['provider member', 'approve', 'deny 404'],
    ['member of the factory', 'requestReview', 'allow'],
    ['imc admin', 'requestReview', 'deny 403'],
    ['member of another factory', 'requestReview', 'deny 404'],
    ['provider member', 'requestReview', 'deny 404'],
]);

test('only IMC administrators see the factory change request queue', function (string $actor, bool $allowed) {
    $user = factoryPolicyActor($actor, Factory::factory()->create());

    expect(Gate::forUser($user)->allows('viewChangeRequestQueue', Factory::class))->toBe($allowed);
})->with([
    ['imc admin', true],
    ['member of the factory', false],
    ['provider member', false],
]);

test('only IMC administrators may list and create factories', function (string $actor, bool $allowed) {
    $user = factoryPolicyActor($actor, Factory::factory()->create());

    expect(Gate::forUser($user)->allows('viewAny', Factory::class))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('create', Factory::class))->toBe($allowed);
})->with([
    ['imc admin', true],
    ['member of the factory', false],
    ['provider member', false],
]);
