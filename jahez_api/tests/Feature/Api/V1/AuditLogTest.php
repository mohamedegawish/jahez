<?php

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Factory;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lists entries newest first with actor and subject for an IMC administrator', function () {
    $admin = User::factory()->imcAdmin()->create(['name' => 'Platform Admin']);
    $factory = Factory::factory()->create();
    AuditLog::record(AuditEvent::FactoryCreated, $admin, $factory, ['name' => 'Delta Foods']);
    AuditLog::record(AuditEvent::LoggedOut, $admin, $admin);
    Sanctum::actingAs($admin);

    $response = $this->getJson(route('api.v1.audit-logs.index'));

    $response->assertOk()
        ->assertJsonPath('data.0.event', 'auth.logged_out')
        ->assertJsonPath('data.1.event', 'factory.created')
        ->assertJsonPath('data.1.actor', ['id' => $admin->id, 'name' => 'Platform Admin', 'email' => $admin->email])
        ->assertJsonPath('data.1.subject', ['type' => 'factory', 'id' => $factory->id])
        ->assertJsonPath('data.1.metadata', ['name' => 'Delta Foods']);
});

it('filters by event, actor and subject', function () {
    $admin = User::factory()->imcAdmin()->create();
    $otherAdmin = User::factory()->imcAdmin()->create();
    $factory = Factory::factory()->create();
    $target = AuditLog::record(AuditEvent::FactoryUpdated, $admin, $factory);
    AuditLog::record(AuditEvent::FactoryUpdated, $otherAdmin, $factory);
    AuditLog::record(AuditEvent::FactoryUpdated, $admin, Factory::factory()->create());
    AuditLog::record(AuditEvent::LoggedOut, $admin, $admin);
    Sanctum::actingAs($admin);

    $response = $this->getJson(route('api.v1.audit-logs.index', [
        'event' => 'factory.updated',
        'actor_user_id' => $admin->id,
        'subject_type' => 'factory',
        'subject_id' => $factory->id,
    ]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
});

it('filters whole UTC days, including the end day', function () {
    $admin = User::factory()->imcAdmin()->create();
    $this->travelTo('2026-10-01 23:59:59');
    AuditLog::record(AuditEvent::LoggedOut, $admin);
    $this->travelTo('2026-10-02 00:00:00');
    $firstOfDay = AuditLog::record(AuditEvent::LoggedOut, $admin);
    $this->travelTo('2026-10-02 23:59:59');
    $lastOfDay = AuditLog::record(AuditEvent::LoggedOut, $admin);
    $this->travelTo('2026-10-03 00:00:00');
    AuditLog::record(AuditEvent::LoggedOut, $admin);
    Sanctum::actingAs($admin);

    $response = $this->getJson(route('api.v1.audit-logs.index', ['from' => '2026-10-02', 'to' => '2026-10-02']));

    expect($response->json('data.*.id'))->toBe([$lastOfDay->id, $firstOfDay->id]);
});

it('pages through entries with a cursor', function () {
    $admin = User::factory()->imcAdmin()->create();
    $entries = collect(range(1, 3))->map(fn () => AuditLog::record(AuditEvent::LoggedOut, $admin));
    Sanctum::actingAs($admin);

    $firstPage = $this->getJson(route('api.v1.audit-logs.index', ['per_page' => 2]));
    $secondPage = $this->getJson(route('api.v1.audit-logs.index', ['per_page' => 2, 'cursor' => $firstPage->json('meta.next_cursor')]));

    expect($firstPage->json('data.*.id'))->toBe([$entries[2]->id, $entries[1]->id])
        ->and($secondPage->json('data.*.id'))->toBe([$entries[0]->id])
        ->and($secondPage->json('meta.next_cursor'))->toBeNull();
});

it('rejects a cursor the API did not issue with 422', function (mixed $cursorContent) {
    Sanctum::actingAs(User::factory()->imcAdmin()->create());
    $cursor = is_string($cursorContent) ? $cursorContent : rtrim(strtr(base64_encode((string) json_encode($cursorContent)), '+/', '-_'), '=');

    $response = $this->getJson(route('api.v1.audit-logs.index', ['cursor' => $cursor]));

    $response->assertUnprocessable()->assertJsonPath('errors.cursor.0', 'The cursor is invalid.');
})->with([
    'no entry id' => [['_pointsToNextItems' => true]],
    'null entry id' => [['id' => null, '_pointsToNextItems' => true]],
    'no direction' => [['id' => 5]],
    'a direction that is not true or false' => [['id' => 5, '_pointsToNextItems' => 'sideways']],
    'a bare number' => [1],
    'not an encoded cursor' => ['not-a-cursor'],
]);

it('rejects filters outside the allow-list with 422', function (array $query, string $field) {
    Sanctum::actingAs(User::factory()->imcAdmin()->create());

    $response = $this->getJson(route('api.v1.audit-logs.index', $query));

    $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'unknown event' => [['event' => 'user.deleted'], 'event'],
    'SQL in the event filter' => [['event' => "x' OR '1'='1"], 'event'],
    'unknown subject type' => [['subject_type' => 'App\\Models\\User', 'subject_id' => 1], 'subject_type'],
    'subject type without id' => [['subject_type' => 'factory'], 'subject_id'],
    'subject id without type' => [['subject_id' => 1], 'subject_type'],
    'non-numeric actor' => [['actor_user_id' => '1 OR 1=1'], 'actor_user_id'],
    'date in another format' => [['from' => '02/10/2026'], 'from'],
    'end before start' => [['from' => '2026-10-02', 'to' => '2026-10-01'], 'to'],
    'page size above 100' => [['per_page' => 101], 'per_page'],
]);

it('accepts an end date without a start date', function () {
    Sanctum::actingAs(User::factory()->imcAdmin()->create());

    $this->getJson(route('api.v1.audit-logs.index', ['to' => '2026-10-02']))->assertOk();
});

it('returns 403 to factory and provider members', function (User $member) {
    Sanctum::actingAs($member);

    $this->getJson(route('api.v1.audit-logs.index'))->assertForbidden();
})->with([
    'factory member' => [fn (): User => User::factory()->factoryMember()->create()],
    'provider member' => [fn (): User => User::factory()->providerMember()->create()],
]);

it('returns 401 without a token', function () {
    $this->getJson(route('api.v1.audit-logs.index'))->assertUnauthorized();
});
