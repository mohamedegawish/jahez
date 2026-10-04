<?php

use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\ServiceCategory;
use App\Models\ServiceProvider;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

it('lists the seven categories in workbook order with their services, to any signed-in account', function (Closure $makeUser) {
    Sanctum::actingAs($makeUser());

    $response = $this->getJson(route('api.v1.catalog.categories.index'));

    $response->assertOk()
        ->assertJsonCount(7, 'data')
        ->assertJsonPath('data.0.code', 'erp_business_applications')
        ->assertJsonPath('data.0.name_ar', 'نظم تخطيط وإدارة موارد المؤسسات والتطبيقات الرقمية')
        ->assertJsonPath('data.0.name_en', 'ERP & Business Applications')
        ->assertJsonPath('data.0.services.0.code', 'erp_business_applications.01')
        ->assertJsonPath('data.6.services.3.name_ar', 'إدارة التدريب والثقافة الرقمية.');
    expect(collect($response->json('data'))->sum(fn (array $category): int => count($category['services'])))->toBe(42);
})->with([
    'IMC administrator' => [fn () => User::factory()->imcAdmin()->create()],
    'factory member' => [fn () => User::factory()->factoryMember()->create()],
    'provider member' => [fn () => User::factory()->providerMember()->create()],
]);

it('shows one category with its services', function () {
    Sanctum::actingAs(User::factory()->create());
    $category = ServiceCategory::query()->where('code', 'automation_ot')->firstOrFail();

    $this->getJson(route('api.v1.catalog.categories.show', $category))
        ->assertOk()
        ->assertJsonPath('data.name_en', 'Automation & OT')
        ->assertJsonCount(8, 'data.services');
});

it('lists services, optionally of one category', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.v1.catalog.services.index'))->assertOk()->assertJsonCount(42, 'data');
    $this->getJson(route('api.v1.catalog.services.index', ['filter' => ['category' => 'cloud_infrastructure']]))
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('data.0.category.code', 'cloud_infrastructure');
});

it('lists for a factory member only the services an eligible provider offers', function () {
    $factory = Factory::factory()->inSectors('food')->create();
    ServiceProvider::factory()->approved()->inSectors('food')->offering('automation_ot.03')->create();
    ServiceProvider::factory()->inSectors('food')->offering('ai_data_analytics.01')->create();
    ServiceProvider::factory()->approved()->inSectors('chemical')->offering('cloud_infrastructure.02')->create();
    Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

    $response = $this->getJson(route('api.v1.catalog.services.index', ['filter' => ['eligible' => 1]]));

    expect($response->json('data.*.code'))->toBe(['automation_ot.03']);
});

it('rejects the eligible filter for accounts without a factory', function () {
    Sanctum::actingAs(User::factory()->imcAdmin()->create());

    $this->getJson(route('api.v1.catalog.services.index', ['filter' => ['eligible' => 1]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.eligible']);
});

it('rejects an unknown category or filter key with 422', function (array $filter, string $field) {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.v1.catalog.services.index', ['filter' => $filter]))->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'unknown category' => [['category' => 'textiles'], 'filter.category'],
    'unknown key' => [['provider' => 1], 'filter'],
]);

it('shows one service with its category', function () {
    Sanctum::actingAs(User::factory()->create());
    $service = CatalogService::query()->where('code', 'digital_engineering_smart_manufacturing.01')->firstOrFail();

    $this->getJson(route('api.v1.catalog.services.show', $service))
        ->assertOk()
        ->assertJsonPath('data.name_ar', 'التوأم الرقمي (Digital Twin).')
        ->assertJsonPath('data.category.code', 'digital_engineering_smart_manufacturing');
});

it('returns 404 for a service that does not exist', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.v1.catalog.services.show', 999999))->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('returns 401 without a token', function () {
    $this->getJson(route('api.v1.catalog.categories.index'))->assertUnauthorized();
});

it('lists for a factory member the services recommended for its current readiness category', function () {
    $factory = Factory::factory()->inSectors('food')->create();
    storedReadinessAssessment($factory, 'b');
    Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

    $response = $this->getJson(route('api.v1.catalog.services.index', ['filter' => ['recommended' => 1]]));

    expect($response->json('data.*.code'))->toBe([
        'automation_ot.01',
        'automation_ot.02',
        'automation_ot.03',
        'automation_ot.04',
        'ot_ics_cybersecurity.02',
        'ot_ics_cybersecurity.07',
        'ai_data_analytics.01',
    ]);
});

it('rejects the recommended filter before the factory has completed an assessment', function () {
    Sanctum::actingAs(User::factory()->factoryMember(Factory::factory()->create())->create());

    $this->getJson(route('api.v1.catalog.services.index', ['filter' => ['recommended' => 1]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.recommended']);
});
