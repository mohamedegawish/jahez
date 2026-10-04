<?php

use App\Models\EvaluationCriterion;
use App\Models\MaturityTier;
use App\Models\PathwayLevel;
use App\Models\Sector;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

it('lists the sectors in source order to any signed-in account', function (Closure $makeUser) {
    Sanctum::actingAs($makeUser());

    $response = $this->getJson(route('api.v1.reference.sectors'));

    $response->assertOk();
    expect($response->json('data.*.code'))->toBe(Sector::query()->orderBy('sort_order')->pluck('code')->all())
        ->and($response->json('data.0'))->toHaveKeys(['code', 'name_ar', 'name_en']);
})->with([
    'factory member' => [fn () => User::factory()->factoryMember()->create()],
    'provider member' => [fn () => User::factory()->providerMember()->create()],
    'IMC administrator' => [fn () => User::factory()->imcAdmin()->create()],
]);

it('lists the configured factory sizes', function () {
    Sanctum::actingAs(User::factory()->create());

    expect($this->getJson(route('api.v1.reference.factory-sizes'))->json('data'))->toBe([
        ['code' => 'small', 'name_ar' => 'الصغيرة'],
        ['code' => 'medium', 'name_ar' => 'المتوسطة'],
        ['code' => 'large', 'name_ar' => 'الكبيرة'],
    ]);

    config(['jahez.factories.sizes' => ['small' => 'الصغيرة', 'medium' => 'المتوسطة']]);
    expect($this->getJson(route('api.v1.reference.factory-sizes'))->json('data.*.code'))->toBe(['small', 'medium']);
});

it('lists the maturity tiers with their pathway level, and no score range', function () {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson(route('api.v1.reference.maturity-tiers'));

    $tier = MaturityTier::query()->with('pathwayLevel.pathway')->orderBy('sort_order')->firstOrFail();
    expect($response->json('data.*.code'))->toBe(MaturityTier::query()->orderBy('sort_order')->pluck('code')->all())
        ->and($response->json('data.0.approved_path_ar'))->toBe($tier->approved_path_ar)
        ->and($response->json('data.0.pathway_level.pathway.code'))->toBe($tier->pathwayLevel?->pathway?->code)
        ->and($response->json('data.0'))->not->toHaveKeys(['score_min', 'score_max']);
});

it('lists the pathways with every level, scope item and provider requirement in source order', function () {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson(route('api.v1.reference.pathways'));

    $levels = collect($response->json('data'))->flatMap(fn (array $pathway): array => $pathway['levels']);
    expect($levels->pluck('code')->sort()->values()->all())->toBe(PathwayLevel::query()->orderBy('code')->pluck('code')->all());
    foreach (PathwayLevel::query()->with(['scopeItems', 'providerRequirements'])->get() as $level) {
        $listed = $levels->firstWhere('code', $level->code);
        expect(array_column($listed['scope_items'], 'text_ar'))->toBe($level->scopeItems->sortBy('sort_order')->pluck('text_ar')->values()->all())
            ->and($listed['provider_requirements'])->toBe($level->providerRequirements->sortBy('sort_order')->pluck('text_ar')->values()->all());
    }
});

it('lists the current evaluation criteria with the source weights, which total 100', function () {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson(route('api.v1.reference.evaluation-criteria'));

    expect($response->json('data.*.code'))->toBe(['technical_expertise', 'technical_cloud_model', 'knowledge_transfer', 'financial_flexibility', 'technical_support_sla'])
        ->and($response->json('data.*.weight_percent'))->toBe(['30.00', '25.00', '20.00', '15.00', '10.00'])
        ->and($response->json('data.*.version'))->each->toBe((int) EvaluationCriterion::query()->max('version'));
});

it('returns 401 without a token', function (string $routeName) {
    $this->getJson(route($routeName))->assertUnauthorized();
})->with(['api.v1.reference.sectors', 'api.v1.reference.factory-sizes', 'api.v1.reference.maturity-tiers', 'api.v1.reference.pathways', 'api.v1.reference.evaluation-criteria']);
