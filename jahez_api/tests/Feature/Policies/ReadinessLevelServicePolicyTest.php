<?php

use App\Models\ReadinessLevelService;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('only IMC administrators read and change the services of each readiness level', function (string $actor, bool $view, bool $manage) {
    $user = match ($actor) {
        'imc admin' => User::factory()->imcAdmin()->create(),
        'factory member' => User::factory()->factoryMember()->create(),
        'provider member' => User::factory()->providerMember(ServiceProvider::factory()->approved()->create())->create(),
    };

    expect(Gate::forUser($user)->allows('viewAny', ReadinessLevelService::class))->toBe($view)
        ->and(Gate::forUser($user)->allows('manage', ReadinessLevelService::class))->toBe($manage);
})->with([
    ['imc admin', true, true],
    ['factory member', false, false],
    ['provider member', false, false],
]);
