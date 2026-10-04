<?php

use App\Models\PublicAnnouncement;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('record decisions for each actor', function (Closure $actor, string $ability, string $expectedDecision) {
    $announcement = new PublicAnnouncement(['title' => 'T', 'description' => 'D', 'color' => 'green']);
    $announcement->save();

    $response = $ability === 'update'
        ? Gate::forUser($actor())->inspect($ability, $announcement)
        : Gate::forUser($actor())->inspect($ability, PublicAnnouncement::class);

    expect($response->allowed() ? 'allow' : 'deny '.($response->status() ?? 403))->toBe($expectedDecision);
})->with([
    [fn () => User::factory()->imcAdmin()->create(), 'viewAny', 'allow'],
    [fn () => User::factory()->imcAdmin()->create(), 'create', 'allow'],
    [fn () => User::factory()->imcAdmin()->create(), 'update', 'allow'],
    [fn () => User::factory()->factoryMember()->create(), 'viewAny', 'deny 403'],
    [fn () => User::factory()->factoryMember()->create(), 'create', 'deny 403'],
    [fn () => User::factory()->factoryMember()->create(), 'update', 'deny 404'],
    [fn () => User::factory()->providerMember()->create(), 'viewAny', 'deny 403'],
    [fn () => User::factory()->providerMember()->create(), 'update', 'deny 404'],
]);
