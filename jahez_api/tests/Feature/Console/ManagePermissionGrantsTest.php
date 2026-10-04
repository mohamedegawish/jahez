<?php

use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\User;

it('grants and revokes an individual permission, with the reason audited', function () {
    $admin = User::factory()->imcAdmin()->create(['email' => 'finance@example.test']);

    $this->artisan('jahez:permissions', ['action' => 'grant', 'email' => 'finance@example.test', 'permission' => 'financial_policies.approve', '--reason' => 'Finance director'])
        ->expectsOutputToContain('Granted financial_policies.approve to finance@example.test.')
        ->assertSuccessful();
    expect($admin->refresh()->hasPermission(Permission::FinancialPoliciesApprove))->toBeTrue();

    $this->artisan('jahez:permissions', ['action' => 'grant', 'email' => 'finance@example.test', 'permission' => 'financial_policies.approve', '--reason' => 'Again'])
        ->expectsOutputToContain('already holds')
        ->assertSuccessful();

    $this->artisan('jahez:permissions', ['action' => 'revoke', 'email' => 'finance@example.test', 'permission' => 'financial_policies.approve', '--reason' => 'Left the department'])
        ->expectsOutputToContain('Revoked financial_policies.approve from finance@example.test.')
        ->assertSuccessful();
    expect($admin->refresh()->hasPermission(Permission::FinancialPoliciesApprove))->toBeFalse();

    $entries = AuditLog::query()->whereIn('event', [AuditEvent::PermissionGranted->value, AuditEvent::PermissionRevoked->value])->orderBy('id')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->subject_id)->toBe($admin->id)
        ->and($entries[0]->metadata)->toEqual(['permission' => 'financial_policies.approve', 'reason' => 'Finance director'])
        ->and($entries[1]->metadata)->toEqual(['permission' => 'financial_policies.approve', 'reason' => 'Left the department']);
});

it('refuses what cannot be granted', function (array $arguments, string $error) {
    User::factory()->imcAdmin()->create(['email' => 'admin@example.test']);
    User::factory()->factoryMember()->create(['email' => 'member@example.test']);
    User::factory()->imcAdmin()->create(['email' => 'gone@example.test', 'deactivated_at' => now()]);

    $this->artisan('jahez:permissions', ['action' => 'grant', '--reason' => 'Because', ...$arguments])
        ->expectsOutputToContain($error)
        ->assertFailed();
    $this->assertDatabaseCount('user_permission_grants', 0);
})->with([
    'a role permission' => [['email' => 'admin@example.test', 'permission' => 'users.create'], 'The permission must be one of'],
    'an unknown permission' => [['email' => 'admin@example.test', 'permission' => 'everything'], 'The permission must be one of'],
    'a factory member' => [['email' => 'member@example.test', 'permission' => 'payments.record'], 'Only IMC administrators can hold these permissions.'],
    'a deactivated administrator' => [['email' => 'gone@example.test', 'permission' => 'payments.record'], 'The account is deactivated.'],
    'an unknown account' => [['email' => 'nobody@example.test', 'permission' => 'payments.record'], 'No account has this email address.'],
    'no reason' => [['email' => 'admin@example.test', 'permission' => 'payments.record', '--reason' => ''], 'Give a --reason'],
]);

it('lists the grants', function () {
    $admin = financeAdmin(Permission::PaymentsRecord);

    $this->artisan('jahez:permissions', ['action' => 'list'])
        ->expectsOutputToContain($admin->email)
        ->assertSuccessful();
});
