<?php

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('record decisions for each actor', function (Closure $actor, string $ability, string $expectedDecision) {
    $response = Gate::forUser($actor())->inspect($ability, $ability === 'update' ? new SiteSetting : SiteSetting::class);

    expect($response->allowed() ? 'allow' : 'deny '.($response->status() ?? 403))->toBe($expectedDecision);
})->with([
    [fn () => User::factory()->imcAdmin()->create(), 'viewAny', 'allow'],
    [fn () => User::factory()->imcAdmin()->create(), 'update', 'allow'],
    [fn () => User::factory()->factoryMember()->create(), 'viewAny', 'deny 403'],
    [fn () => User::factory()->factoryMember()->create(), 'update', 'deny 404'],
    [fn () => User::factory()->providerMember()->create(), 'viewAny', 'deny 403'],
    [fn () => User::factory()->providerMember()->create(), 'update', 'deny 404'],
]);
