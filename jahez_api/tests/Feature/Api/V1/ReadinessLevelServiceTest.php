<?php

use App\Enums\AuditEvent;
use App\Enums\ReadinessCategoryCode;
use App\Models\AuditLog;
use App\Models\CatalogService;
use App\Models\ReadinessLevelService;
use App\Models\ServiceProvider;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * IMC administration of the services each readiness level makes available (ADR-025).
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->admin = User::factory()->imcAdmin()->create();
    $this->erp = CatalogService::query()->where('code', 'erp_business_applications.01')->sole();
    $this->cyber = CatalogService::query()->where('code', 'ot_ics_cybersecurity.01')->sole();
});

it('lists the four levels with their ranges, services, provider counts and recommended hints', function () {
    ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
    ServiceProvider::factory()->inSectors('food')->offering('erp_business_applications.01')->create();
    Sanctum::actingAs($this->admin);
    $this->putJson(route('api.v1.readiness-levels.services.update', ['basic', $this->erp]))->assertCreated();
    $this->putJson(route('api.v1.readiness-levels.services.update', ['advanced', $this->erp]))->assertCreated();
    $this->putJson(route('api.v1.readiness-levels.services.update', ['advanced', $this->cyber]))->assertCreated();

    $response = $this->getJson(route('api.v1.readiness-levels.index'))->assertOk();
    $levels = collect($response->json('data.levels'))->keyBy('code');

    expect($levels->keys()->all())->toBe(['b4_automation', 'basic', 'advanced', 'smart'])
        ->and([$levels['b4_automation']['min_score'], $levels['b4_automation']['max_score']])->toBe([10, 17])
        ->and([$levels['basic']['min_score'], $levels['basic']['max_score']])->toBe([18, 25])
        ->and([$levels['advanced']['min_score'], $levels['advanced']['max_score']])->toBe([26, 33])
        ->and([$levels['smart']['min_score'], $levels['smart']['max_score']])->toBe([34, 40])
        ->and(array_column(array_column($levels['basic']['services'], 'service'), 'code'))->toBe(['erp_business_applications.01'])
        // Only the approved provider with an approved listing counts.
        ->and($levels['basic']['services'][0]['approved_provider_count'])->toBe(1)
        ->and($levels['b4_automation']['recommended_service_ids'])->toContain($this->erp->id)
        ->and($response->json('data.summary.multi_level_service_ids'))->toBe([$this->erp->id])
        ->and($response->json('data.summary.without_provider_service_ids'))->toBe([$this->cyber->id])
        ->and(count($response->json('data.summary.unassigned_services')))->toBe(CatalogService::query()->count() - 2);
});

it('switches a service off and on for a level, and repeating a call changes nothing', function () {
    Sanctum::actingAs($this->admin);
    $url = route('api.v1.readiness-levels.services.update', ['basic', $this->erp]);

    $this->putJson($url)->assertCreated()->assertJsonPath('data.is_active', true);
    $this->putJson($url, ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $this->putJson($url, ['is_active' => false])->assertOk();

    expect(ReadinessLevelService::query()->sole()->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('event', AuditEvent::ReadinessLevelServiceAssigned)->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::ReadinessLevelServiceUpdated)->count())->toBe(1);
});

it('removes a service from a level without touching the catalog', function () {
    Sanctum::actingAs($this->admin);
    $this->putJson(route('api.v1.readiness-levels.services.update', ['basic', $this->erp]))->assertCreated();

    $this->deleteJson(route('api.v1.readiness-levels.services.destroy', ['basic', $this->erp]))->assertNoContent();
    $this->deleteJson(route('api.v1.readiness-levels.services.destroy', ['basic', $this->erp]))->assertNotFound();

    expect(ReadinessLevelService::query()->count())->toBe(0)
        ->and(CatalogService::query()->whereKey($this->erp->id)->exists())->toBeTrue();
});

it('accepts only the four category codes as a level', function () {
    Sanctum::actingAs($this->admin);

    $this->putJson('/api/v1/readiness-levels/expert/services/'.$this->erp->id)->assertNotFound();
    $this->putJson('/api/v1/readiness-levels/basic/services/999999')->assertNotFound();
    $this->putJson(route('api.v1.readiness-levels.services.update', ['basic', $this->erp]), ['is_active' => 'perhaps'])->assertUnprocessable();
});

it('lets only IMC administrators read or change level services', function (string $actor) {
    $user = match ($actor) {
        'factory member' => User::factory()->factoryMember()->create(),
        'provider member' => User::factory()->providerMember(ServiceProvider::factory()->approved()->create())->create(),
    };
    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.readiness-levels.index'))->assertForbidden();
    $this->putJson(route('api.v1.readiness-levels.services.update', ['basic', $this->erp]))->assertForbidden();
    $this->deleteJson(route('api.v1.readiness-levels.services.destroy', ['basic', $this->erp]))->assertForbidden();

    expect(ReadinessLevelService::query()->count())->toBe(0);
})->with(['factory member', 'provider member']);

it('stores the level as a stable category code that applies to every questionnaire version', function () {
    Sanctum::actingAs($this->admin);
    $this->putJson(route('api.v1.readiness-levels.services.update', ['smart', $this->erp]))->assertCreated();

    expect(ReadinessLevelService::query()->sole()->level)->toBe(ReadinessCategoryCode::Smart);
});
