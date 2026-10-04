<?php

use App\Models\Factory;
use App\Models\FactoryAssessment;
use App\Models\MaturityTier;
use App\Models\ServiceProvider;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

/**
 * Store a legacy manual classification (ADR-016) as it was recorded before the readiness
 * assessment replaced it (ADR-018).
 */
function legacyClassification(Factory $factory, string $tier = 'basic_dx', string $assessedOn = '2026-10-01', ?User $recordedBy = null): FactoryAssessment
{
    $assessment = new FactoryAssessment;
    $assessment->factory_id = $factory->id;
    $assessment->maturity_tier_id = MaturityTier::query()->where('code', $tier)->value('id');
    $assessment->justification = 'Field visit on 1 October: ERP partly in place, no MES.';
    $assessment->assessed_on = $assessedOn;
    $assessment->recorded_by_user_id = ($recordedBy ?? User::factory()->imcAdmin()->create())->id;
    $assessment->save();

    return $assessment;
}

it('lists a legacy classification with the source tier and pathway, and no score', function () {
    $factory = Factory::factory()->create();
    $admin = User::factory()->imcAdmin()->create(['name' => 'IMC Assessor']);
    $assessment = legacyClassification($factory, recordedBy: $admin);
    Sanctum::actingAs($admin);
    $tier = MaturityTier::query()->where('code', 'basic_dx')->with('pathwayLevel.pathway')->firstOrFail();

    $response = $this->getJson(route('api.v1.factories.assessments.index', $factory));

    $response->assertOk()
        ->assertJsonPath('data.0.id', $assessment->id)
        ->assertJsonPath('data.0.classification_method', 'manual')
        ->assertJsonPath('data.0.score', null)
        ->assertJsonPath('data.0.maturity_tier.code', 'basic_dx')
        ->assertJsonPath('data.0.maturity_tier.approved_path_ar', $tier->approved_path_ar)
        ->assertJsonPath('data.0.maturity_tier.pathway_level.code', $tier->pathwayLevel?->code)
        ->assertJsonPath('data.0.assessed_on', '2026-10-01')
        ->assertJsonPath('data.0.recorded_by', ['id' => $admin->id, 'name' => 'IMC Assessor']);
    expect(Schema::getColumnListing('factory_assessments'))->not->toContain('score');
});

it('lists by assessment date, latest recorded first on the same date', function () {
    $factory = Factory::factory()->create();
    $september = legacyClassification($factory, 'advanced_dx', '2026-09-01');
    $backfilled = legacyClassification($factory, 'basic_dx', '2025-03-01');
    $sameDayLater = legacyClassification($factory, 'smart_dx', '2026-09-01');
    Sanctum::actingAs(User::factory()->imcAdmin()->create());

    $response = $this->getJson(route('api.v1.factories.assessments.index', $factory));

    expect($response->json('data.*.id'))->toBe([$sameDayLater->id, $september->id, $backfilled->id]);
});

it('lists only the factory asked for', function () {
    $factory = Factory::factory()->create();
    legacyClassification(Factory::factory()->create());
    Sanctum::actingAs(User::factory()->imcAdmin()->create());

    $this->getJson(route('api.v1.factories.assessments.index', $factory))->assertOk()->assertJsonCount(0, 'data');
});

it('no longer records manual classifications: the route answers 405 and stores nothing', function () {
    $factory = Factory::factory()->create();
    Sanctum::actingAs(User::factory()->imcAdmin()->create());

    $this->postJson(route('api.v1.factories.assessments.index', $factory), [
        'maturity_tier' => 'basic_dx',
        'justification' => 'Field visit',
        'assessed_on' => '2026-10-01',
    ])->assertMethodNotAllowed();
    $this->assertDatabaseCount('factory_assessments', 0);
});

it('lets a factory member read their own legacy classifications', function () {
    $factory = Factory::factory()->create();
    legacyClassification($factory);
    Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

    $this->getJson(route('api.v1.factories.assessments.index', $factory))->assertOk()->assertJsonCount(1, 'data');
});

it('returns 404 to anyone outside the factory and IMC', function (Closure $makeOutsider) {
    $factory = Factory::factory()->create();
    Sanctum::actingAs($makeOutsider());

    $this->getJson(route('api.v1.factories.assessments.index', $factory))->assertNotFound();
})->with([
    'member of another factory' => [fn () => User::factory()->factoryMember()->create()],
    'provider member' => [fn () => User::factory()->providerMember(ServiceProvider::factory()->create())->create()],
]);

it('returns 401 without a token', function () {
    $this->getJson(route('api.v1.factories.assessments.index', Factory::factory()->create()))->assertUnauthorized();
});

it('refuses to change or delete a legacy classification', function (Closure $tamper) {
    $assessment = legacyClassification(Factory::factory()->create());

    expect(fn () => $tamper($assessment))->toThrow(LogicException::class, 'Factory assessments are append-only.');
    expect(FactoryAssessment::query()->findOrFail($assessment->id)->justification)->toBe('Field visit on 1 October: ERP partly in place, no MES.');
})->with([
    'update' => [function (FactoryAssessment $assessment): void {
        $assessment->justification = 'Rewritten';
        $assessment->save();
    }],
    'delete' => [fn (FactoryAssessment $assessment) => $assessment->delete()],
]);
