<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('only IMC administrators may read the audit log', function (string $actor, bool $allowed) {
    $user = match ($actor) {
        'imc admin' => User::factory()->imcAdmin()->create(),
        'factory member' => User::factory()->factoryMember()->create(),
        'provider member' => User::factory()->providerMember()->create(),
    };

    expect(Gate::forUser($user)->allows('viewAny', AuditLog::class))->toBe($allowed);
})->with([
    ['imc admin', true],
    ['factory member', false],
    ['provider member', false],
]);
