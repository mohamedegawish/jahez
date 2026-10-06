<?php

use App\Enums\AuditEvent;
use App\Enums\NotificationEvent;
use App\Enums\ServiceListingStatus;
use App\Models\AuditLog;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\ServiceListingPackage;
use App\Models\ServiceProvider;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Listing packages and prices (ADR-027, owner decisions 2026-10-06): a provider lists
 * several packages per service, each a name with an optional monthly price, annual price
 * and number of users (at least one price); a change sends the listing back to IMC review
 * and hides it from factories until IMC approves it again.
 */
const PACKAGES_ERP = 'erp_business_applications.01';

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->provider = ServiceProvider::factory()->approved()->inSectors('food')->offering(PACKAGES_ERP)->create(['name' => 'ERP House']);
    $this->providerMember = User::factory()->providerMember($this->provider)->create();
    $this->erp = CatalogService::query()->where('code', PACKAGES_ERP)->sole();
    $this->factoryMember = User::factory()->factoryMember(factoryWithEveryService('food'))->create();
});

/**
 * @return list<array<string, mixed>>
 */
function twoErpPackages(): array
{
    return [
        ['name_ar' => 'الباقة الأساسية', 'monthly_price' => 1500, 'annual_price' => '15000.5', 'users_count' => 10],
        ['name_ar' => 'الباقة المتقدمة', 'annual_price' => '40000'],
    ];
}

function erpListingStatus(ServiceProvider $provider): string
{
    return (string) DB::table('catalog_service_service_provider')
        ->where('service_provider_id', $provider->id)
        ->where('catalog_service_id', CatalogService::query()->where('code', PACKAGES_ERP)->value('id'))
        ->value('status');
}

it('lets a provider list packages, which sends the approved listing back to review until IMC approves it again', function () {
    $admin = User::factory()->imcAdmin()->create();
    Sanctum::actingAs($this->factoryMember);
    $this->getJson(route('api.v1.service-listings.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.packages', []);

    Sanctum::actingAs($this->providerMember);
    $this->putJson(route('api.v1.service-providers.services.packages.update', [$this->provider, $this->erp]), ['packages' => twoErpPackages()])
        ->assertOk()
        ->assertJsonPath('data.service_listings.0.status', 'pending')
        ->assertJsonPath('data.service_listings.0.packages.0.name_ar', 'الباقة الأساسية')
        ->assertJsonPath('data.service_listings.0.packages.0.monthly_price', '1500.00')
        ->assertJsonPath('data.service_listings.0.packages.0.annual_price', '15000.50')
        ->assertJsonPath('data.service_listings.0.packages.0.users_count', 10)
        ->assertJsonPath('data.service_listings.0.packages.1.monthly_price', null)
        ->assertJsonPath('data.service_listings.0.packages.1.annual_price', '40000.00')
        ->assertJsonPath('data.service_listings.0.packages.1.users_count', null);

    $audit = AuditLog::query()->where('event', AuditEvent::ServiceListingPackagesUpdated->value)->sole();
    expect(erpListingStatus($this->provider))->toBe('pending')
        ->and($audit->metadata)->toEqual(['service' => PACKAGES_ERP, 'packages' => ['from' => 0, 'to' => 2], 'status' => ['from' => 'approved', 'to' => 'pending']])
        ->and(json_encode($audit->metadata))->not->toContain('1500')->not->toContain('40000');

    // Hidden from factories while IMC reviews the new prices.
    Sanctum::actingAs($this->factoryMember->fresh());
    $this->getJson(route('api.v1.service-listings.index'))->assertOk()->assertJsonCount(0, 'data');

    expect($admin->notifications()->where('data->event', NotificationEvent::ServiceListingPackagesChanged->value)->count())->toBe(1);
    Sanctum::actingAs($admin);
    $this->getJson(route('api.v1.service-listings.index', ['filter' => ['listing_status' => 'pending']]))
        ->assertOk()
        ->assertJsonPath('data.0.packages.1.annual_price', '40000.00');
    $this->postJson(route('api.v1.service-providers.services.review', [$this->provider, $this->erp]), ['decision' => 'approved'])->assertOk();

    Sanctum::actingAs($this->factoryMember->fresh());
    $this->getJson(route('api.v1.service-listings.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.packages.0.name_ar', 'الباقة الأساسية')
        ->assertJsonPath('data.0.packages.1.name_ar', 'الباقة المتقدمة');
});

it('changes nothing when the same packages are sent again, and keeps a pending listing pending', function () {
    Sanctum::actingAs($this->providerMember);
    $route = route('api.v1.service-providers.services.packages.update', [$this->provider, $this->erp]);
    $this->putJson($route, ['packages' => twoErpPackages()])->assertOk();
    $this->putJson($route, ['packages' => twoErpPackages()])->assertOk();

    expect(AuditLog::query()->where('event', AuditEvent::ServiceListingPackagesUpdated->value)->count())->toBe(1);

    $this->putJson($route, ['packages' => [twoErpPackages()[1]]])->assertOk()->assertJsonPath('data.service_listings.0.status', 'pending');
    expect(AuditLog::query()->where('event', AuditEvent::ServiceListingPackagesUpdated->value)->latest('id')->firstOrFail()->metadata)
        ->toEqual(['service' => PACKAGES_ERP, 'packages' => ['from' => 2, 'to' => 1]])
        ->and(ServiceListingPackage::query()->count())->toBe(1);

    // An empty list removes every package; the listing is reviewed again.
    $this->putJson($route, ['packages' => []])->assertOk()->assertJsonPath('data.service_listings.0.packages', []);
});

it('refuses a package without any price and malformed values', function (array $package, string $field) {
    Sanctum::actingAs($this->providerMember);

    $this->putJson(route('api.v1.service-providers.services.packages.update', [$this->provider, $this->erp]), ['packages' => [$package]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors("packages.0.{$field}");
    expect(ServiceListingPackage::query()->count())->toBe(0)
        ->and(erpListingStatus($this->provider))->toBe('approved');
})->with([
    'no price' => [['name_ar' => 'مجانية', 'users_count' => 5], 'monthly_price'],
    'negative price' => [['name_ar' => 'س', 'monthly_price' => -1], 'monthly_price'],
    'three decimals' => [['name_ar' => 'س', 'annual_price' => '10.555'], 'annual_price'],
    'no users' => [['name_ar' => 'س', 'monthly_price' => 10, 'users_count' => 0], 'users_count'],
    'no name' => [['monthly_price' => 10], 'name_ar'],
]);

it('refuses more than ten packages, repeated names and unknown fields', function () {
    Sanctum::actingAs($this->providerMember);
    $route = route('api.v1.service-providers.services.packages.update', [$this->provider, $this->erp]);
    $eleven = array_map(fn (int $i): array => ['name_ar' => "باقة {$i}", 'monthly_price' => $i], range(1, 11));

    $this->putJson($route, ['packages' => $eleven])->assertUnprocessable()->assertJsonValidationErrors('packages');
    $this->putJson($route, ['packages' => [['name_ar' => 'أ', 'monthly_price' => 1], ['name_ar' => 'أ', 'monthly_price' => 2]]])->assertUnprocessable()->assertJsonValidationErrors('packages.1.name_ar');
    $this->putJson($route, ['packages' => [['name_ar' => 'أ', 'monthly_price' => 1, 'discount' => 5]]])->assertUnprocessable()->assertJsonValidationErrors('packages.0');
    $this->putJson($route, [])->assertUnprocessable()->assertJsonValidationErrors('packages');
});

it('leaves a suspended listing to IMC and refuses a service the provider does not list', function () {
    $suspended = ServiceProvider::factory()->approved()->listing(ServiceListingStatus::Suspended, PACKAGES_ERP)->create();
    Sanctum::actingAs(User::factory()->providerMember($suspended)->create());
    $this->putJson(route('api.v1.service-providers.services.packages.update', [$suspended, $this->erp]), ['packages' => twoErpPackages()])->assertConflict();

    Sanctum::actingAs($this->providerMember);
    $other = CatalogService::query()->where('code', 'automation_ot.01')->sole();
    $this->putJson(route('api.v1.service-providers.services.packages.update', [$this->provider, $other]), ['packages' => twoErpPackages()])->assertNotFound();

    expect(ServiceListingPackage::query()->count())->toBe(0);
});

it('lets only the provider\'s own members change its packages', function (Closure $makeUser, int $status) {
    Sanctum::actingAs($makeUser());

    $this->putJson(route('api.v1.service-providers.services.packages.update', [$this->provider, $this->erp]), ['packages' => twoErpPackages()])->assertStatus($status);
    expect(ServiceListingPackage::query()->count())->toBe(0);
})->with([
    'IMC administrator' => [fn () => User::factory()->imcAdmin()->create(), 403],
    'another provider' => [fn () => User::factory()->providerMember(ServiceProvider::factory()->approved()->create())->create(), 404],
    'factory member' => [fn () => User::factory()->factoryMember(Factory::factory()->create())->create(), 404],
]);

it('drops the packages with the listing when the provider stops offering the service', function () {
    Sanctum::actingAs($this->providerMember);
    $this->putJson(route('api.v1.service-providers.services.packages.update', [$this->provider, $this->erp]), ['packages' => twoErpPackages()])->assertOk();

    $this->patchJson(route('api.v1.service-providers.update', $this->provider), ['services' => ['automation_ot.01']])->assertOk();

    expect(ServiceListingPackage::query()->count())->toBe(0);
});

it('refuses a package without a price in the database too', function () {
    $package = new ServiceListingPackage;
    $package->service_provider_id = $this->provider->id;
    $package->catalog_service_id = $this->erp->id;
    $package->position = 1;
    $package->name_ar = 'بلا سعر';

    expect(fn () => $package->save())->toThrow(QueryException::class);
});
