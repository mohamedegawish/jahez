<?php

use App\Models\Factory;
use App\Models\ReadinessAssessment;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Gate;

test('record decisions for each actor on a factory\'s readiness assessments', function (string $actor, string $ability, string $expectedDecision) {
    $this->seed(ReferenceDataSeeder::class);
    $factory = Factory::factory()->create();
    $user = match ($actor) {
        'imc admin' => User::factory()->imcAdmin()->create(),
        'member of the factory' => User::factory()->factoryMember($factory)->create(),
        'member of another factory' => User::factory()->factoryMember()->create(),
        'provider member' => User::factory()->providerMember()->create(),
    };
    $arguments = $ability === 'view' ? storedReadinessAssessment($factory) : [ReadinessAssessment::class, $factory];

    $response = Gate::forUser($user)->inspect($ability, $arguments);

    expect($response->allowed() ? 'allow' : 'deny '.($response->status() ?? 403))->toBe($expectedDecision);
})->with([
    ['imc admin', 'viewAny', 'allow'],
    ['member of the factory', 'viewAny', 'allow'],
    ['member of another factory', 'viewAny', 'deny 404'],
    ['provider member', 'viewAny', 'deny 404'],
    ['imc admin', 'view', 'allow'],
    ['member of the factory', 'view', 'allow'],
    ['member of another factory', 'view', 'deny 404'],
    ['provider member', 'view', 'deny 404'],
    ['imc admin', 'create', 'deny 403'],
    ['member of the factory', 'create', 'allow'],
    ['member of another factory', 'create', 'deny 404'],
    ['provider member', 'create', 'deny 404'],
]);
