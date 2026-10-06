<?php

use App\Enums\TransformationPlanStatus;
use App\Models\Factory;
use App\Models\ServiceProvider;
use App\Models\TransformationPlan;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who may read and write a factory's transformation plan (ADR-025): IMC writes and reads
 * everything; the factory's own members read a plan once published and write nothing;
 * providers and other factories are told it does not exist.
 */
function decisionOf(User $user, string $ability, mixed $arguments): string
{
    $response = Gate::forUser($user)->inspect($ability, $arguments);

    return $response->allowed() ? 'allow' : 'deny '.($response->status() ?? 403);
}

test('the transformation plan matrix', function (string $actor, TransformationPlanStatus $status, string $view, string $manage, string $versions, string $create) {
    $factory = Factory::factory()->create();
    $plan = new TransformationPlan;
    $plan->factory_id = $factory->id;
    $plan->status = $status;
    $plan->is_open = $status === TransformationPlanStatus::Closed ? null : true;
    $plan->save();

    $user = match ($actor) {
        'imc admin' => User::factory()->imcAdmin()->create(),
        'factory member' => User::factory()->factoryMember($factory)->create(),
        'other factory member' => User::factory()->factoryMember()->create(),
        'provider member' => User::factory()->providerMember(ServiceProvider::factory()->approved()->create())->create(),
    };

    expect(decisionOf($user, 'view', $plan))->toBe($view)
        ->and(decisionOf($user, 'manage', $plan))->toBe($manage)
        ->and(decisionOf($user, 'viewVersions', $plan))->toBe($versions)
        ->and(decisionOf($user, 'create', [TransformationPlan::class, $factory]))->toBe($create);
})->with([
    ['imc admin', TransformationPlanStatus::Draft, 'allow', 'allow', 'allow', 'allow'],
    ['imc admin', TransformationPlanStatus::Published, 'allow', 'allow', 'allow', 'allow'],
    ['factory member', TransformationPlanStatus::Draft, 'deny 404', 'deny 404', 'deny 404', 'deny 403'],
    ['factory member', TransformationPlanStatus::Published, 'allow', 'deny 403', 'deny 403', 'deny 403'],
    ['factory member', TransformationPlanStatus::Suspended, 'allow', 'deny 403', 'deny 403', 'deny 403'],
    ['factory member', TransformationPlanStatus::Closed, 'allow', 'deny 403', 'deny 403', 'deny 403'],
    ['other factory member', TransformationPlanStatus::Published, 'deny 404', 'deny 404', 'deny 404', 'deny 404'],
    ['provider member', TransformationPlanStatus::Published, 'deny 404', 'deny 404', 'deny 404', 'deny 404'],
]);

test('only IMC administrators and factory members may list plans', function (string $actor, bool $allowed) {
    $user = match ($actor) {
        'imc admin' => User::factory()->imcAdmin()->create(),
        'factory member' => User::factory()->factoryMember()->create(),
        'provider member' => User::factory()->providerMember(ServiceProvider::factory()->approved()->create())->create(),
    };

    expect(Gate::forUser($user)->allows('viewAny', TransformationPlan::class))->toBe($allowed);
})->with([
    ['imc admin', true],
    ['factory member', true],
    ['provider member', false],
]);
