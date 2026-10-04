<?php

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Factory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

it('records the request IP address and request ID with stable subject names', function () {
    $factory = Factory::factory()->create();
    Route::middleware('api')->post('api/v1/__test/audit', fn () => ['id' => AuditLog::record(AuditEvent::FactoryUpdated, null, $factory)->id]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->postJson('/api/v1/__test/audit', [], ['X-Request-Id' => 'trace-audit-0001']);

    $entry = AuditLog::query()->findOrFail($response->json('id'));
    expect($entry->ip_address)->toBe('203.0.113.7')
        ->and($entry->request_id)->toBe('trace-audit-0001')
        ->and($entry->subject_type)->toBe('factory')
        ->and($entry->subject_id)->toBe($factory->id);
});

it('drops metadata keys that could hold secrets, at any depth', function () {
    $entry = AuditLog::record(AuditEvent::UserRenamed, metadata: [
        'to' => 'New Name',
        'password' => 'hunter2-hunter2',
        'nested' => ['access_token' => 'abc', 'reset_token' => 'def', 'client_secret' => 'ghi', 'kept' => 'yes'],
    ]);

    expect($entry->refresh()->metadata)->toEqual([
        'to' => 'New Name',
        'nested' => ['kept' => 'yes'],
    ]);
});

/*
 * A foreign key to users would make every entry take a shared lock on the acting user's
 * row, which can deadlock with the administrator row locks taken in UserController.
 * Here the actor's row is still locked by the test's open transaction; a second
 * connection must be able to write an entry for that actor without waiting.
 */
it('writes an entry without waiting for a lock on the acting user', function () {
    $actor = User::factory()->create();
    config(['database.connections.second' => config('database.connections.'.DB::getDefaultConnection())]);
    $secondConnection = DB::connection('second');
    $secondConnection->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $secondConnection->beginTransaction();

    try {
        $secondConnection->table('audit_logs')->insert(['event' => AuditEvent::LoggedOut->value, 'actor_user_id' => $actor->id, 'created_at' => now()]);
        $entriesSeenBySecondConnection = $secondConnection->table('audit_logs')->where('actor_user_id', $actor->id)->count();
    } finally {
        $secondConnection->rollBack();
        DB::purge('second');
    }

    expect($entriesSeenBySecondConnection)->toBe(1);
});

it('refuses to update or delete an entry', function (Closure $tamper) {
    $entry = AuditLog::record(AuditEvent::LoggedOut, User::factory()->create());

    expect(fn () => $tamper($entry))->toThrow(LogicException::class, 'Audit log entries are immutable.');

    expect($entry->fresh()?->event)->toBe(AuditEvent::LoggedOut);
})->with([
    'update' => [function (AuditLog $entry): void {
        $entry->event = AuditEvent::LoginSucceeded;
        $entry->save();
    }],
    'delete' => [fn (AuditLog $entry) => $entry->delete()],
]);
