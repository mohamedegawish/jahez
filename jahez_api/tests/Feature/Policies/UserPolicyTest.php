<?php

use App\Models\Factory;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Creates the acting user for a matrix row, relative to the target account.
 */
function userPolicyActor(string $actor, User $target): User
{
    return match ($actor) {
        'imc admin' => User::factory()->imcAdmin()->create(),
        'the account itself' => $target,
        'colleague in the same factory' => User::factory()->factoryMember($target->industrialFactory()->firstOrFail())->create(),
        'member of another factory' => User::factory()->factoryMember()->create(),
        'provider member' => User::factory()->providerMember()->create(),
    };
}

test('record decisions for each actor', function (string $actor, string $ability, string $expectedDecision) {
    $target = User::factory()->factoryMember(Factory::factory()->create())->create();

    $response = Gate::forUser(userPolicyActor($actor, $target))->inspect($ability, $target);

    expect($response->allowed() ? 'allow' : 'deny '.($response->status() ?? 403))->toBe($expectedDecision);
})->with([
    ['imc admin', 'view', 'allow'],
    ['the account itself', 'view', 'allow'],
    ['colleague in the same factory', 'view', 'allow'],
    ['member of another factory', 'view', 'deny 404'],
    ['provider member', 'view', 'deny 404'],
    ['imc admin', 'update', 'allow'],
    ['the account itself', 'update', 'deny 403'],
    ['colleague in the same factory', 'update', 'deny 403'],
    ['member of another factory', 'update', 'deny 404'],
    ['provider member', 'update', 'deny 404'],
]);

test('only IMC administrators may list and create accounts', function (string $actor, bool $allowed) {
    $user = userPolicyActor($actor, User::factory()->factoryMember()->create());

    expect(Gate::forUser($user)->allows('viewAny', User::class))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('create', User::class))->toBe($allowed);
})->with([
    ['imc admin', true],
    ['the account itself', false],
    ['provider member', false],
]);
