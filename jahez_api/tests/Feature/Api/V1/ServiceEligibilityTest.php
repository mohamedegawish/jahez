<?php

use App\Enums\ReadinessCategoryCode;
use App\Enums\ServiceListingStatus;
use App\Http\Requests\Api\V1\StoreServiceRequestRequest;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\ReadinessAssessment;
use App\Models\ReadinessLevelService;
use App\Models\ServicePromotion;
use App\Models\ServiceProvider;
use App\Models\ServiceRequest;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Readiness-based service eligibility (ADR-025): a factory sees and requests only the
 * services IMC made available to its current readiness level or a level below it
 * (ADR-026), through every path, and nothing a client sends widens it.
 */
const ELIGIBILITY_ERP = 'erp_business_applications.01';
const ELIGIBILITY_AUTOMATION = 'automation_ot.01';

/**
 * Make the service available (or not) to the level, as IMC would.
 */
function levelService(ReadinessCategoryCode $level, string $code, bool $active = true): ReadinessLevelService
{
    $row = new ReadinessLevelService;
    $row->level = $level;
    $row->catalog_service_id = CatalogService::query()->where('code', $code)->value('id');
    $row->is_active = $active;
    $row->save();

    return $row;
}

/**
 * A signed-in member of a food-sector factory whose current assessment totals $total.
 */
function assessedFactoryMember(int $total): User
{
    $factory = Factory::factory()->inSectors('food')->create();
    storedReadinessAssessment($factory, readinessChoicesForTotal($total));
    $member = User::factory()->factoryMember($factory)->create();
    Sanctum::actingAs($member);

    return $member;
}

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    // ELIGIBILITY_ERP is made available to Basic (18–25), automation only to Advanced (26–33).
    levelService(ReadinessCategoryCode::Basic, ELIGIBILITY_ERP);
    levelService(ReadinessCategoryCode::Advanced, ELIGIBILITY_AUTOMATION);
    $this->provider = ServiceProvider::factory()->approved()->inSectors('food')->offering(ELIGIBILITY_ERP, ELIGIBILITY_AUTOMATION)->create();
});

it('shows a factory only the services of its level, on every path', function () {
    $member = assessedFactoryMember(20);
    $erp = CatalogService::query()->where('code', ELIGIBILITY_ERP)->sole();
    $automation = CatalogService::query()->where('code', ELIGIBILITY_AUTOMATION)->sole();

    expect($this->getJson(route('api.v1.catalog.services.index'))->assertOk()->json('data.*.code'))->toBe([ELIGIBILITY_ERP])
        ->and($this->getJson(route('api.v1.catalog.services.index', ['filter' => ['eligible' => 1]]))->json('data.*.code'))->toBe([ELIGIBILITY_ERP])
        ->and(collect($this->getJson(route('api.v1.catalog.categories.index'))->json('data'))->flatMap(fn (array $category) => array_column($category['services'], 'code'))->all())->toBe([ELIGIBILITY_ERP])
        ->and($this->getJson(route('api.v1.service-listings.index'))->assertOk()->json('data.*.service.code'))->toBe([ELIGIBILITY_ERP])
        ->and($this->getJson(route('api.v1.provider-directory', ['filter' => ['service' => ELIGIBILITY_AUTOMATION]]))->assertOk()->json('data'))->toBe([])
        ->and($this->getJson(route('api.v1.provider-directory.show', $this->provider))->assertOk()->json('data.services.*.code'))->toBe([ELIGIBILITY_ERP])
        ->and($this->getJson(route('api.v1.factories.service-eligibility.show', $member->factory_id))->assertOk()->json('data.services.*.service.code'))->toBe([ELIGIBILITY_ERP]);

    $this->getJson(route('api.v1.catalog.services.show', $erp))->assertOk();
    $this->getJson(route('api.v1.catalog.services.show', $automation))->assertNotFound();
    $this->getJson(route('api.v1.factories.service-eligibility.providers', [$member->factory_id, $automation]))->assertNotFound();
    $this->getJson(route('api.v1.factories.service-eligibility.providers', [$member->factory_id, $erp]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->provider->id);
    $this->getJson(route('api.v1.factories.service-eligibility.show', $member->factory_id))
        ->assertJsonPath('data.status', 'eligible')
        ->assertJsonPath('data.readiness.level', 'basic')
        ->assertJsonPath('data.readiness.assessed_level', 'basic')
        ->assertJsonPath('data.readiness.unlocked_by', 'assessment')
        ->assertJsonMissingPath('data.readiness.total_score')
        ->assertJsonPath('data.services.0.eligible_providers_count', 1);
    $this->getJson(route('api.v1.service-listings.index'))
        ->assertJsonPath('meta.readiness.level', 'basic')
        ->assertJsonMissingPath('meta.readiness.total_score');

    Sanctum::actingAs(User::factory()->imcAdmin()->create());
    $this->getJson(route('api.v1.factories.service-eligibility.show', $member->factory_id))
        ->assertJsonPath('data.readiness.total_score', 20);
});

it('refuses a service request for a service the level does not make available, even by direct call', function () {
    assessedFactoryMember(20);

    $this->postJson(route('api.v1.service-requests.store'), [
        'service' => ELIGIBILITY_AUTOMATION,
        'title' => 'Automation',
        'need' => 'Automate the packing line.',
        'provider_ids' => [$this->provider->id],
        // Ignored: the level, the factory and the score come from the server.
        'level' => 'advanced',
        'readiness_category' => 'smart',
        'total_score' => 40,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.service.0', StoreServiceRequestRequest::SERVICE_NOT_AVAILABLE);

    expect(ServiceRequest::query()->count())->toBe(0);
});

it('lets the factory request a service its level makes available', function () {
    $member = assessedFactoryMember(20);

    $this->postJson(route('api.v1.service-requests.store'), [
        'service' => ELIGIBILITY_ERP,
        'title' => 'ELIGIBILITY_ERP',
        'need' => 'Replace spreadsheets.',
        'provider_ids' => [$this->provider->id],
    ])->assertCreated()->assertJsonPath('data.factory.id', $member->factory_id);
});

it('hides a service IMC switched off for the level', function () {
    assessedFactoryMember(20);
    ReadinessLevelService::query()->where('level', ReadinessCategoryCode::Basic)->update(['is_active' => false]);

    expect($this->getJson(route('api.v1.catalog.services.index'))->json('data'))->toBe([])
        ->and($this->getJson(route('api.v1.service-listings.index'))->json('data'))->toBe([]);
    $this->postJson(route('api.v1.service-requests.store'), [
        'service' => ELIGIBILITY_ERP, 'title' => 'ELIGIBILITY_ERP', 'need' => 'Need.', 'provider_ids' => [$this->provider->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['service']);
});

it('never offers an unapproved provider or a listing IMC has not approved', function (string $case) {
    $member = assessedFactoryMember(20);
    $other = match ($case) {
        'pending provider' => ServiceProvider::factory()->inSectors('food')->offering(ELIGIBILITY_ERP)->create(),
        'pending listing' => ServiceProvider::factory()->approved()->inSectors('food')->listing(ServiceListingStatus::Pending, ELIGIBILITY_ERP)->create(),
        'suspended listing' => ServiceProvider::factory()->approved()->inSectors('food')->listing(ServiceListingStatus::Suspended, ELIGIBILITY_ERP)->create(),
        'provider in another sector' => ServiceProvider::factory()->approved()->inSectors('chemical')->offering(ELIGIBILITY_ERP)->create(),
    };
    $erp = CatalogService::query()->where('code', ELIGIBILITY_ERP)->sole();

    expect($this->getJson(route('api.v1.service-listings.index'))->json('data.*.provider.id'))->toBe([$this->provider->id])
        ->and($this->getJson(route('api.v1.factories.service-eligibility.providers', [$member->factory_id, $erp]))->json('data.*.id'))->toBe([$this->provider->id])
        ->and($this->getJson(route('api.v1.provider-directory'))->json('data.*.id'))->toBe([$this->provider->id]);
    $this->getJson(route('api.v1.provider-directory.show', $other))->assertNotFound();
    $this->postJson(route('api.v1.service-requests.store'), [
        'service' => ELIGIBILITY_ERP, 'title' => 'ELIGIBILITY_ERP', 'need' => 'Need.', 'provider_ids' => [$other->id],
    ])->assertUnprocessable()->assertJsonPath('errors.provider_ids.0', StoreServiceRequestRequest::INELIGIBLE_PROVIDERS);
})->with(['pending provider', 'pending listing', 'suspended listing', 'provider in another sector']);

it('never lets a promotion add a listing the factory\'s level does not allow', function () {
    assessedFactoryMember(20);
    $promotion = new ServicePromotion;
    $promotion->service_provider_id = $this->provider->id;
    $promotion->catalog_service_id = CatalogService::query()->where('code', ELIGIBILITY_AUTOMATION)->value('id');
    $promotion->priority = 100;
    $promotion->starts_at = now()->subDay();
    $promotion->save();

    expect($this->getJson(route('api.v1.service-listings.index', ['filter' => ['promoted' => 1]]))->json('data'))->toBe([])
        ->and($this->getJson(route('api.v1.service-listings.index'))->json('data.*.service.code'))->toBe([ELIGIBILITY_ERP]);
});

it('gives a factory without an assessment no service at all, and says why', function () {
    $member = User::factory()->factoryMember(Factory::factory()->inSectors('food')->create())->create();
    Sanctum::actingAs($member);

    expect($this->getJson(route('api.v1.catalog.services.index'))->json('data'))->toBe([])
        ->and($this->getJson(route('api.v1.provider-directory'))->json('data'))->toBe([]);
    $this->getJson(route('api.v1.service-listings.index'))
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.eligibility_status', 'no_assessment');
    $this->getJson(route('api.v1.factories.service-eligibility.show', $member->factory_id))
        ->assertJsonPath('data.status', 'no_assessment')
        ->assertJsonPath('data.readiness', null)
        ->assertJsonPath('data.services', []);
    $this->postJson(route('api.v1.service-requests.store'), [
        'service' => ELIGIBILITY_ERP, 'title' => 'ELIGIBILITY_ERP', 'need' => 'Need.', 'provider_ids' => [$this->provider->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['service']);
});

it('follows the factory\'s new level after a new assessment, keeps the lower level\'s services and the earlier result', function () {
    $member = assessedFactoryMember(20);
    $earlier = ReadinessAssessment::query()->where('factory_id', $member->factory_id)->sole();

    $this->postJson(route('api.v1.factories.readiness-assessments.store', $member->factory_id), readinessPayload(readinessChoicesForTotal(30)))->assertCreated();

    // Levels are cumulative (ADR-026): Advanced keeps the Basic service.
    expect($this->getJson(route('api.v1.catalog.services.index'))->json('data.*.code'))->toEqualCanonicalizing([ELIGIBILITY_ERP, ELIGIBILITY_AUTOMATION])
        ->and($earlier->fresh()->total_score)->toBe(20)
        ->and($earlier->fresh()->category->code)->toBe(ReadinessCategoryCode::Basic);
});

it('refuses to add providers once IMC removed the service from the factory\'s level', function () {
    $marketplace = marketplaceRequest(1);
    ReadinessLevelService::query()->delete();
    $extra = ServiceProvider::factory()->approved()->inSectors('food')->offering(ELIGIBILITY_ERP)->create();
    Sanctum::actingAs($marketplace['factoryMember']);

    $this->postJson(route('api.v1.service-requests.providers.store', $marketplace['serviceRequest']), ['provider_ids' => [$extra->id]])
        ->assertUnprocessable();
    expect($marketplace['serviceRequest']->providerRequests()->count())->toBe(1);
});

it('does not show one factory\'s eligibility to another factory or to a provider', function () {
    $member = assessedFactoryMember(20);
    $otherMember = User::factory()->factoryMember(Factory::factory()->inSectors('food')->create())->create();
    $providerMember = User::factory()->providerMember($this->provider)->create();

    foreach ([$otherMember, $providerMember] as $outsider) {
        Sanctum::actingAs($outsider);
        $this->getJson(route('api.v1.factories.service-eligibility.show', $member->factory_id))->assertNotFound();
    }

    Sanctum::actingAs(User::factory()->imcAdmin()->create());
    $this->getJson(route('api.v1.factories.service-eligibility.show', $member->factory_id))->assertOk()->assertJsonPath('data.readiness.level', 'basic');
});

it('leaves the whole catalog to IMC administrators and providers', function () {
    $count = CatalogService::query()->count();

    foreach ([User::factory()->imcAdmin()->create(), User::factory()->providerMember($this->provider)->create()] as $user) {
        Sanctum::actingAs($user);
        expect($this->getJson(route('api.v1.catalog.services.index'))->json('data'))->toHaveCount($count);
        $this->getJson(route('api.v1.catalog.services.show', CatalogService::query()->where('code', ELIGIBILITY_AUTOMATION)->sole()))->assertOk();
    }
});
