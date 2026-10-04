<?php

use App\Models\ReadinessQuestionnaire;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('only IMC administrators may manage the questionnaire versions', function (Closure $makeUser, bool $allowed) {
    expect(Gate::forUser($makeUser())->allows('manage', ReadinessQuestionnaire::class))->toBe($allowed);
})->with([
    'imc admin' => [fn () => User::factory()->imcAdmin()->create(), true],
    'factory member' => [fn () => User::factory()->factoryMember()->create(), false],
    'provider member' => [fn () => User::factory()->providerMember(ServiceProvider::factory()->create())->create(), false],
]);
