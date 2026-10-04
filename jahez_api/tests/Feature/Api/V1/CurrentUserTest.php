<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Factory;
use App\Models\ServiceProvider;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('returns an IMC administrator with every role permission and no organization', function () {
    $admin = User::factory()->imcAdmin()->create();
    Sanctum::actingAs($admin);

    $response = $this->getJson(route('api.v1.me'));

    $response->assertOk()
        ->assertJsonPath('data.id', $admin->id)
        ->assertJsonPath('data.role', 'imc_admin')
        ->assertJsonPath('data.organization', null)
        ->assertJsonPath('data.permissions', array_map(fn (Permission $permission): string => $permission->value, Role::ImcAdmin->permissions()));
    expect($response->json('data'))->not->toHaveKeys(['password', 'remember_token'])
        // ADR-023: preparing and approving financial policies and recording payments are granted per person.
        ->and($response->json('data.permissions'))->not->toContain('financial_policies.manage', 'financial_policies.approve', 'payments.record');
});

it('returns a factory member with their factory and no platform permissions', function () {
    $factory = Factory::factory()->create(['name' => 'Delta Foods']);
    Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

    $response = $this->getJson(route('api.v1.me'));

    $response->assertOk()
        ->assertJsonPath('data.role', 'factory_member')
        ->assertJsonPath('data.organization', ['type' => 'factory', 'id' => $factory->id, 'name' => 'Delta Foods'])
        ->assertJsonPath('data.permissions', []);
});

it('returns a provider member with their service provider and no platform permissions', function () {
    $serviceProvider = ServiceProvider::factory()->create(['name' => 'Nile Systems']);
    Sanctum::actingAs(User::factory()->providerMember($serviceProvider)->create());

    $response = $this->getJson(route('api.v1.me'));

    $response->assertOk()
        ->assertJsonPath('data.role', 'provider_member')
        ->assertJsonPath('data.organization', ['type' => 'service_provider', 'id' => $serviceProvider->id, 'name' => 'Nile Systems'])
        ->assertJsonPath('data.permissions', []);
});

it('returns 401 without a token', function () {
    $response = $this->getJson(route('api.v1.me'));

    $response->assertUnauthorized();
});

it('returns 401 for a token of an account deactivated after the token was issued', function () {
    $user = User::factory()->create();
    $token = bearerTokenFor($user);
    $user->deactivated_at = now();
    $user->save();

    $response = $this->withToken($token)->getJson(route('api.v1.me'));

    $response->assertUnauthorized();
});
