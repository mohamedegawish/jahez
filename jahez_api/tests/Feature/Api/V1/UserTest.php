<?php

use App\Enums\Role;
use App\Http\Controllers\Api\V1\UserController;
use App\Jobs\SendAccountInvitation;
use App\Models\Factory;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

describe('index', function () {
    it('lists accounts with their organization for an IMC administrator', function () {
        $admin = User::factory()->imcAdmin()->create();
        $member = User::factory()->factoryMember()->create();
        Sanctum::actingAs($admin);

        $response = $this->getJson(route('api.v1.users.index'));

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.1.id', $member->id)
            ->assertJsonPath('data.1.organization.type', 'factory');
    });

    it('returns 403 to a factory member', function () {
        Sanctum::actingAs(User::factory()->factoryMember()->create());

        $this->getJson(route('api.v1.users.index'))->assertForbidden();
    });
});

describe('store', function () {
    it('creates a factory member and queues an invitation to set a password', function () {
        Queue::fake([SendAccountInvitation::class]);
        $factory = Factory::factory()->create(['name' => 'Delta Foods']);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->postJson(route('api.v1.users.store'), [
            'name' => 'Mona Adel',
            'email' => 'mona@example.test',
            'role' => 'factory_member',
            'factory_id' => $factory->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.role', 'factory_member')
            ->assertJsonPath('data.organization', ['type' => 'factory', 'id' => $factory->id, 'name' => 'Delta Foods'])
            ->assertJsonPath('data.is_active', true);
        $invitee = User::query()->where('email', 'mona@example.test')->firstOrFail();
        expect($invitee->role)->toBe(Role::FactoryMember)->and($invitee->factory_id)->toBe($factory->id);
        Queue::assertPushed(SendAccountInvitation::class, fn (SendAccountInvitation $job): bool => $job->user->is($invitee));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'mona@example.test']);
    });

    it('creates a provider member linked to their provider', function () {
        Queue::fake([SendAccountInvitation::class]);
        $serviceProvider = ServiceProvider::factory()->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->postJson(route('api.v1.users.store'), [
            'name' => 'Omar Said',
            'email' => 'omar@example.test',
            'role' => 'provider_member',
            'service_provider_id' => $serviceProvider->id,
        ]);

        $response->assertCreated()->assertJsonPath('data.organization.type', 'service_provider');
        $this->assertDatabaseHas('users', ['email' => 'omar@example.test', 'service_provider_id' => $serviceProvider->id, 'factory_id' => null]);
    });

    it('ignores fields an administrator may not set directly', function () {
        Queue::fake([SendAccountInvitation::class]);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->postJson(route('api.v1.users.store'), [
            'name' => 'New Admin',
            'email' => 'new-admin@example.test',
            'role' => 'imc_admin',
            'email_verified_at' => '2026-01-01 00:00:00',
            'deactivated_at' => '2026-01-01 00:00:00',
            'password' => 'chosen-by-the-admin',
        ])->assertCreated();

        $invitee = User::query()->where('email', 'new-admin@example.test')->firstOrFail();
        expect($invitee->email_verified_at)->toBeNull()
            ->and($invitee->isActive())->toBeTrue()
            ->and(password_verify('chosen-by-the-admin', $invitee->password))->toBeFalse();
    });

    it('rejects an organization that does not match the role with 422', function (array $payload, string $field, string $message) {
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->postJson(route('api.v1.users.store'), ['name' => 'Someone', 'email' => 'someone@example.test', ...$payload]);

        $response->assertUnprocessable()->assertJsonPath("errors.{$field}.0", $message);
        $this->assertDatabaseMissing('users', ['email' => 'someone@example.test']);
    })->with([
        'factory member without a factory' => [['role' => 'factory_member'], 'factory_id', 'The factory id field is required.'],
        'provider member without a provider' => [['role' => 'provider_member'], 'service_provider_id', 'The service provider id field is required.'],
        'administrator with a factory' => [fn (): array => ['role' => 'imc_admin', 'factory_id' => Factory::factory()->create()->id], 'factory_id', 'The factory id field is prohibited.'],
        'factory member with a provider' => [fn (): array => ['role' => 'factory_member', 'factory_id' => Factory::factory()->create()->id, 'service_provider_id' => ServiceProvider::factory()->create()->id], 'service_provider_id', 'The service provider id field is prohibited.'],
        'unknown role' => [['role' => 'super_admin'], 'role', 'The selected role is invalid.'],
        'factory that does not exist' => [['role' => 'factory_member', 'factory_id' => 999999], 'factory_id', 'The selected factory id is invalid.'],
    ]);

    it('rejects an email that already has an account with 422', function () {
        User::factory()->create(['email' => 'taken@example.test']);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->postJson(route('api.v1.users.store'), ['name' => 'Someone', 'email' => 'taken@example.test', 'role' => 'imc_admin']);

        $response->assertUnprocessable()->assertJsonPath('errors.email.0', 'The email has already been taken.');
    });

    it('returns 403 to a member trying to create an account and creates nothing', function () {
        Sanctum::actingAs(User::factory()->factoryMember()->create());

        $response = $this->postJson(route('api.v1.users.store'), ['name' => 'Escalated', 'email' => 'escalated@example.test', 'role' => 'imc_admin']);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'escalated@example.test']);
    });
});

describe('show', function () {
    it('shows a member themselves and a colleague from the same organization', function () {
        $factory = Factory::factory()->create();
        $member = User::factory()->factoryMember($factory)->create();
        $colleague = User::factory()->factoryMember($factory)->create();
        Sanctum::actingAs($member);

        $this->getJson(route('api.v1.users.show', $member))->assertOk()->assertJsonPath('data.id', $member->id);
        $this->getJson(route('api.v1.users.show', $colleague))->assertOk()->assertJsonPath('data.id', $colleague->id);
    });

    it('returns 404 for an account of another organization', function (User $outsider) {
        $member = User::factory()->factoryMember()->create();
        Sanctum::actingAs($outsider);

        $this->getJson(route('api.v1.users.show', $member))->assertNotFound();
    })->with([
        'member of another factory' => [fn (): User => User::factory()->factoryMember()->create()],
        'provider member' => [fn (): User => User::factory()->providerMember()->create()],
    ]);
});

describe('update', function () {
    it('deactivates an account and revokes its tokens and pending reset links', function () {
        $member = User::factory()->create();
        bearerTokenFor($member);
        Password::broker()->createToken($member);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->patchJson(route('api.v1.users.update', $member), ['is_active' => false]);

        $response->assertOk()->assertJsonPath('data.is_active', false);
        expect($member->refresh()->isActive())->toBeFalse();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $member->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $member->email]);
    });

    it('reactivates a deactivated account', function () {
        $member = User::factory()->deactivated()->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->patchJson(route('api.v1.users.update', $member), ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);

        expect($member->refresh()->isActive())->toBeTrue();
    });

    it('lets an administrator deactivate another administrator', function () {
        $otherAdministrator = User::factory()->imcAdmin()->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->patchJson(route('api.v1.users.update', $otherAdministrator), ['is_active' => false])->assertOk();

        expect($otherAdministrator->refresh()->isActive())->toBeFalse();
    });

    it('rejects deactivating an administrator when the acting administrator was deactivated meanwhile, with 422', function () {
        $actingAdministrator = User::factory()->imcAdmin()->deactivated()->create();
        $lastActiveAdministrator = User::factory()->imcAdmin()->create();
        Sanctum::actingAs($actingAdministrator);

        $response = $this->patchJson(route('api.v1.users.update', $lastActiveAdministrator), ['is_active' => false]);

        $response->assertUnprocessable()->assertJsonPath('errors.is_active.0', UserController::LAST_ADMINISTRATOR_MESSAGE);
        expect($lastActiveAdministrator->refresh()->isActive())->toBeTrue();
    });

    it('rejects an administrator deactivating their own account with 422', function () {
        $admin = User::factory()->imcAdmin()->create();
        Sanctum::actingAs($admin);

        $response = $this->patchJson(route('api.v1.users.update', $admin), ['is_active' => false]);

        $response->assertUnprocessable()->assertJsonPath('errors.is_active.0', 'You cannot deactivate your own account.');
        expect($admin->refresh()->isActive())->toBeTrue();
    });

    it('ignores role and organization changes sent by an administrator', function () {
        $member = User::factory()->factoryMember()->create();
        $originalFactoryId = $member->factory_id;
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->patchJson(route('api.v1.users.update', $member), ['name' => 'Renamed', 'role' => 'imc_admin', 'factory_id' => null])->assertOk();

        $member->refresh();
        expect($member->name)->toBe('Renamed')
            ->and($member->role)->toBe(Role::FactoryMember)
            ->and($member->factory_id)->toBe($originalFactoryId);
    });

    it('returns 403 to a member trying to make themselves an administrator', function () {
        $member = User::factory()->factoryMember()->create();
        Sanctum::actingAs($member);

        $response = $this->patchJson(route('api.v1.users.update', $member), ['role' => 'imc_admin', 'name' => 'Me']);

        $response->assertForbidden();
        expect($member->refresh()->role)->toBe(Role::FactoryMember);
    });

    it('returns 404 to a member updating an account of another organization', function () {
        $otherMember = User::factory()->factoryMember()->create(['name' => 'Original']);
        Sanctum::actingAs(User::factory()->factoryMember()->create());

        $this->patchJson(route('api.v1.users.update', $otherMember), ['name' => 'Hijacked'])->assertNotFound();

        expect($otherMember->refresh()->name)->toBe('Original');
    });
});
