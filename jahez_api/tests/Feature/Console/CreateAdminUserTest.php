<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a verified IMC administrator with the prompted password', function () {
    $this->artisan('app:create-admin', ['email' => 'admin@example.test', 'name' => 'Platform Admin'])
        ->expectsQuestion('Password', 'a-strong-passphrase')
        ->expectsQuestion('Confirm password', 'a-strong-passphrase')
        ->expectsOutputToContain('IMC administrator admin@example.test created.')
        ->assertSuccessful();

    $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
    expect($admin->role)->toBe(Role::ImcAdmin)
        ->and($admin->factory_id)->toBeNull()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('a-strong-passphrase', $admin->password))->toBeTrue();
});

it('creates no administrator when the audit entry cannot be written', function () {
    AuditLog::creating(fn (): never => throw new RuntimeException('Audit store unavailable.'));

    expect(fn () => $this->artisan('app:create-admin', ['email' => 'admin@example.test', 'name' => 'Platform Admin'])
        ->expectsQuestion('Password', 'a-strong-passphrase')
        ->expectsQuestion('Confirm password', 'a-strong-passphrase')
        ->run())->toThrow(RuntimeException::class, 'Audit store unavailable.');

    $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
});

it('refuses invalid input and creates nothing', function (string $email, string $password, string $confirmation, string $error) {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->artisan('app:create-admin', ['email' => $email, 'name' => 'Platform Admin'])
        ->expectsQuestion('Password', $password)
        ->expectsQuestion('Confirm password', $confirmation)
        ->expectsOutputToContain($error)
        ->assertFailed();

    expect(User::query()->where('role', Role::ImcAdmin)->count())->toBe(0);
})->with([
    'mismatched confirmation' => ['admin@example.test', 'a-strong-passphrase', 'something-else-entirely', 'The password field confirmation does not match.'],
    'password shorter than 12 characters' => ['admin@example.test', 'short-pass', 'short-pass', 'The password field must be at least 12 characters.'],
    'email that already has an account' => ['taken@example.test', 'a-strong-passphrase', 'a-strong-passphrase', 'The email has already been taken.'],
]);
