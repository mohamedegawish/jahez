<?php

use App\Models\ReadinessQuestionnaire;
use App\Models\ServiceProvider;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

it('returns the current questionnaire with its questions grouped by pillar and four scored choices each', function () {
    Sanctum::actingAs(User::factory()->factoryMember()->create());

    $response = $this->getJson(route('api.v1.readiness-questionnaire.show'));

    $response->assertOk()
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.title_ar', 'إطار تقييم مستوى الجاهزية الرقمية')
        ->assertJsonPath('data.min_score', 10)
        ->assertJsonPath('data.max_score', 40)
        ->assertJsonCount(5, 'data.pillars')
        ->assertJsonPath('data.pillars.0.name_en', 'Strategy & Leadership')
        ->assertJsonPath('data.pillars.0.questions.0.code', 'q1')
        ->assertJsonPath('data.pillars.0.questions.0.text_ar', 'كيف تصف استراتيجية التحول الرقمي في مؤسستك حالياً؟')
        ->assertJsonPath('data.pillars.4.questions.1.number', 10);
    expect(array_map(fn (array $pillar): int => count($pillar['questions']), $response->json('data.pillars')))->toBe([2, 2, 2, 2, 2])
        ->and(array_map(fn (array $choice): array => [$choice['label_ar'], $choice['points']], $response->json('data.pillars.0.questions.0.choices')))
        ->toBe([['أ', 1], ['ب', 2], ['ج', 3], ['د', 4]]);
});

it('returns the categories with their score ranges and the catalog services each roadmap recommends', function () {
    Sanctum::actingAs(User::factory()->factoryMember()->create());

    $response = $this->getJson(route('api.v1.readiness-questionnaire.show'));

    expect(array_map(fn (array $category): array => [$category['code'], $category['min_score'], $category['max_score']], $response->json('data.categories')))->toBe([
        ['b4_automation', 10, 17],
        ['basic', 18, 25],
        ['advanced', 26, 33],
        ['smart', 34, 40],
    ]);
    $response->assertJsonPath('data.categories.0.roadmap.recommendations.3.text_ar', 'إعادة هندسة ورقمنة العمليات.')
        ->assertJsonPath('data.categories.0.roadmap.recommendations.3.services.0.code', 'erp_business_applications.04')
        ->assertJsonPath('data.categories.0.roadmap.recommendations.3.services.1.code', 'dx_consulting_enablement.03')
        ->assertJsonPath('data.categories.0.roadmap.recommendations.3.services.1.category.code', 'dx_consulting_enablement');
});

it('is readable by every signed-in role', function (Closure $makeUser) {
    Sanctum::actingAs($makeUser());

    $this->getJson(route('api.v1.readiness-questionnaire.show'))->assertOk();
})->with([
    'IMC administrator' => [fn () => User::factory()->imcAdmin()->create()],
    'provider member' => [fn () => User::factory()->providerMember(ServiceProvider::factory()->create())->create()],
]);

it('returns 404 when no questionnaire is current', function () {
    ReadinessQuestionnaire::query()->update(['is_current' => null]);
    Sanctum::actingAs(User::factory()->factoryMember()->create());

    $this->getJson(route('api.v1.readiness-questionnaire.show'))->assertNotFound();
});

it('returns 401 without a token', function () {
    $this->getJson(route('api.v1.readiness-questionnaire.show'))->assertUnauthorized();
});
