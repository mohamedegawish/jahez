<?php

use App\Models\Factory;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Inserts a user row directly, bypassing the application, to exercise the database CHECK.
 *
 * @param  array<string, mixed>  $attributes
 */
function insertUserRow(array $attributes): void
{
    DB::table('users')->insert([
        'name' => 'Direct Insert',
        'email' => 'direct-insert@example.test',
        'password' => 'not-a-real-hash',
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
}

it('rejects a user row whose organization link does not match its role', function (array $attributes) {
    expect(fn () => insertUserRow($attributes))->toThrow('users_role_organization_check');

    $this->assertDatabaseMissing('users', ['email' => 'direct-insert@example.test']);
})->with([
    'factory member without a factory' => [['role' => 'factory_member']],
    'provider member without a provider' => [['role' => 'provider_member']],
    'administrator with a factory' => [fn (): array => ['role' => 'imc_admin', 'factory_id' => Factory::factory()->create()->id]],
    'member of a factory and a provider' => [fn (): array => [
        'role' => 'factory_member',
        'factory_id' => Factory::factory()->create()->id,
        'service_provider_id' => ServiceProvider::factory()->create()->id,
    ]],
    'unknown role' => [['role' => 'super_admin']],
]);

it('accepts a user row whose organization link matches its role', function () {
    insertUserRow(['role' => 'factory_member', 'factory_id' => Factory::factory()->create()->id]);

    $this->assertDatabaseHas('users', ['email' => 'direct-insert@example.test', 'role' => 'factory_member']);
});

it('prevents deleting a factory that still has members', function () {
    $factory = Factory::factory()->create();
    User::factory()->factoryMember($factory)->create();

    expect(fn () => $factory->delete())->toThrow('foreign key constraint fails');

    $this->assertModelExists($factory);
});
