<?php

use App\Models\Factory;
use App\Models\FactoryAssessment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('record decisions for each actor on a legacy factory classification', function (string $actor, string $ability, string $expectedDecision) {
    $factory = Factory::factory()->create();
    $user = match ($actor) {
        'imc admin' => User::factory()->imcAdmin()->create(),
        'member of the factory' => User::factory()->factoryMember($factory)->create(),
        'member of another factory' => User::factory()->factoryMember()->create(),
        'provider member' => User::factory()->providerMember()->create(),
    };

    $response = Gate::forUser($user)->inspect($ability, [FactoryAssessment::class, $factory]);

    expect($response->allowed() ? 'allow' : 'deny '.($response->status() ?? 403))->toBe($expectedDecision);
})->with([
    ['imc admin', 'viewAny', 'allow'],
    ['member of the factory', 'viewAny', 'allow'],
    ['member of another factory', 'viewAny', 'deny 404'],
    ['provider member', 'viewAny', 'deny 404'],
    // Manual classification was retired by ADR-018: nobody records one any more.
    ['imc admin', 'create', 'deny 403'],
    ['member of the factory', 'create', 'deny 403'],
]);
