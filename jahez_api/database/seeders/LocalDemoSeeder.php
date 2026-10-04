<?php

namespace Database\Seeders;

use App\Enums\FactoryApprovalStatus;
use App\Enums\Permission;
use App\Enums\ProviderApprovalStatus;
use App\Enums\Role;
use App\Enums\ServiceListingStatus;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\Sector;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Models\UserPermissionGrant;
use Illuminate\Database\Seeder;

/**
 * Synthetic demo organizations and accounts for local development and the Postman
 * collection's negative tests: two factories and two providers, one member each.
 * Every account uses the publicly known password "password", so DatabaseSeeder runs
 * this only in the local and testing environments. Safe to run repeatedly.
 */
class LocalDemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /**
     * @var list<array{email: string, name: string, organization: string, sector: string}>
     */
    private const FACTORY_MEMBERS = [
        ['email' => 'factory-a@example.test', 'name' => 'Demo Factory A Member', 'organization' => 'Demo Factory A', 'sector' => 'food'],
        ['email' => 'factory-b@example.test', 'name' => 'Demo Factory B Member', 'organization' => 'Demo Factory B', 'sector' => 'chemical'],
    ];

    /**
     * Demo providers are approved and offer a few catalog services, so the provider
     * directory and the request flow work locally (Factory A is in P's sector).
     *
     * @var list<array{email: string, name: string, organization: string, sector: string, services: list<string>}>
     */
    private const PROVIDER_MEMBERS = [
        ['email' => 'provider-p@example.test', 'name' => 'Demo Provider P Member', 'organization' => 'Demo Provider P', 'sector' => 'food', 'services' => ['erp_business_applications.01', 'automation_ot.01']],
        ['email' => 'provider-q@example.test', 'name' => 'Demo Provider Q Member', 'organization' => 'Demo Provider Q', 'sector' => 'engineering_metal', 'services' => ['ot_ics_cybersecurity.01', 'automation_ot.01']],
    ];

    /**
     * Demo IMC administrators holding the individually granted financial permissions
     * (ADR-023), so maker-checker approval can be tried locally: one prepares policies and
     * records manual payments, the other approves. No policy and no value is seeded.
     *
     * @var list<array{email: string, name: string, permissions: list<Permission>}>
     */
    private const FINANCE_ADMINS = [
        ['email' => 'finance-maker@example.test', 'name' => 'Demo Finance Maker', 'permissions' => [Permission::FinancialPoliciesManage, Permission::PaymentsRecord]],
        ['email' => 'finance-approver@example.test', 'name' => 'Demo Finance Approver', 'permissions' => [Permission::FinancialPoliciesApprove]],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command->warn('LocalDemoSeeder only runs in the local and testing environments; nothing was created.');

            return;
        }

        $sectorIds = Sector::query()->pluck('id', 'code');

        foreach (self::FACTORY_MEMBERS as $member) {
            $factory = Factory::query()->firstOrCreate(['name' => $member['organization']]);
            $this->approveFactory($factory);
            $factory->sectors()->syncWithoutDetaching([$sectorIds[$member['sector']]]);
            $this->createMember($member, Role::FactoryMember, factoryId: $factory->id);
        }

        foreach (self::PROVIDER_MEMBERS as $member) {
            $serviceProvider = ServiceProvider::query()->firstOrCreate(['name' => $member['organization']]);
            $serviceProvider->sectors()->syncWithoutDetaching([$sectorIds[$member['sector']]]);
            // Demo listings are approved (ADR-021), so they reach the demo factories.
            $serviceProvider->services()->syncWithoutDetaching(
                CatalogService::query()->whereIn('code', $member['services'])->pluck('id')
                    ->mapWithKeys(fn (int $id): array => [$id => ['status' => ServiceListingStatus::Approved->value, 'status_changed_at' => now()]])
                    ->all(),
            );
            $this->approve($serviceProvider);
            $this->createMember($member, Role::ProviderMember, serviceProviderId: $serviceProvider->id);
        }

        foreach (self::FINANCE_ADMINS as $admin) {
            $this->createMember(['email' => $admin['email'], 'name' => $admin['name'], 'organization' => '', 'sector' => ''], Role::ImcAdmin);
            $user = User::query()->where('email', $admin['email'])->firstOrFail();
            foreach ($admin['permissions'] as $permission) {
                if (UserPermissionGrant::query()->where('user_id', $user->id)->where('permission', $permission->value)->doesntExist()) {
                    $grant = new UserPermissionGrant;
                    $grant->user_id = $user->id;
                    $grant->permission = $permission->value;
                    $grant->reason = 'Local demo data';
                    $grant->save();
                }
            }
        }
    }

    private function approveFactory(Factory $factory): void
    {
        if ($factory->approval_status !== FactoryApprovalStatus::Pending) {
            return;
        }

        $factory->approval_status = FactoryApprovalStatus::Approved;
        $factory->approval_reason = 'Local demo data';
        $factory->approval_changed_at = now();
        $factory->save();
    }

    private function approve(ServiceProvider $serviceProvider): void
    {
        if ($serviceProvider->approval_status !== ProviderApprovalStatus::Pending) {
            return;
        }

        $serviceProvider->approval_status = ProviderApprovalStatus::Approved;
        $serviceProvider->approval_reason = 'Local demo data';
        $serviceProvider->approval_changed_at = now();
        $serviceProvider->save();
    }

    /**
     * @param  array{email: string, name: string, organization: string, sector: string, services?: list<string>}  $member
     */
    private function createMember(array $member, Role $role, ?int $factoryId = null, ?int $serviceProviderId = null): void
    {
        if (User::query()->where('email', $member['email'])->exists()) {
            return;
        }

        $user = new User(['name' => $member['name'], 'email' => $member['email'], 'password' => self::PASSWORD]);
        $user->role = $role;
        $user->factory_id = $factoryId;
        $user->service_provider_id = $serviceProviderId;
        $user->email_verified_at = now();
        $user->save();
    }
}
