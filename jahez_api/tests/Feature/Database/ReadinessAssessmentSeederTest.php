<?php

use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\FactoryAssessment;
use App\Models\MaturityTier;
use App\Models\ReadinessAssessment;
use App\Models\ReadinessAssessmentAnswer;
use App\Models\ReadinessCategory;
use App\Models\ReadinessChoice;
use App\Models\ReadinessPillar;
use App\Models\ReadinessQuestion;
use App\Models\ReadinessQuestionnaire;
use App\Models\ReadinessRecommendation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReadinessAssessmentSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Row ids of every readiness reference table and the mapping rows, to compare runs.
 *
 * @return array<string, list<mixed>>
 */
function readinessReferenceRows(): array
{
    return [
        'questionnaires' => ReadinessQuestionnaire::query()->orderBy('id')->pluck('id')->all(),
        'pillars' => ReadinessPillar::query()->orderBy('id')->pluck('id')->all(),
        'questions' => ReadinessQuestion::query()->orderBy('id')->pluck('id')->all(),
        'choices' => ReadinessChoice::query()->orderBy('id')->pluck('id')->all(),
        'categories' => ReadinessCategory::query()->orderBy('id')->pluck('id')->all(),
        'recommendations' => ReadinessRecommendation::query()->orderBy('id')->pluck('id')->all(),
        'mappings' => DB::table('catalog_service_readiness_recommendation')->orderBy('readiness_recommendation_id')->orderBy('catalog_service_id')
            ->get()->map(fn (object $row): string => "{$row->readiness_recommendation_id}:{$row->catalog_service_id}")->all(),
        'catalog' => CatalogService::query()->orderBy('id')->pluck('id')->all(),
    ];
}

it('seeds one current questionnaire of five pillars with two questions each and four scored choices per question', function () {
    $this->seed(ReferenceDataSeeder::class);

    $questionnaire = ReadinessQuestionnaire::query()->where('is_current', true)->sole();
    $pillars = $questionnaire->pillars()->withCount('questions')->get();

    expect(ReadinessQuestionnaire::query()->count())->toBe(1)
        ->and($questionnaire->version)->toBe(1)
        ->and($pillars->pluck('questions_count', 'code')->all())->toBe([
            'strategy_leadership' => 2,
            'processes_operations' => 2,
            'technology_data' => 2,
            'culture_people' => 2,
            'customer_experience' => 2,
        ])
        ->and($questionnaire->questions()->pluck('number')->all())->toBe(range(1, 10));

    foreach ($questionnaire->questions()->with('choices')->get() as $question) {
        expect($question->choices->map(fn (ReadinessChoice $choice): array => [$choice->code, $choice->label_ar, $choice->points])->all())
            ->toBe([['a', 'أ', 1], ['b', 'ب', 2], ['c', 'ج', 3], ['d', 'د', 4]]);
    }
});

it('seeds the four categories with the exact source thresholds', function () {
    $this->seed(ReferenceDataSeeder::class);

    $categories = ReadinessQuestionnaire::query()->where('is_current', true)->sole()->categories()->get();

    expect($categories->map(fn (ReadinessCategory $category): array => [$category->code->value, $category->name_en, $category->min_score, $category->max_score])->all())->toBe([
        ['b4_automation', 'B4 Automation', 10, 17],
        ['basic', 'Basic', 18, 25],
        ['advanced', 'Advanced', 26, 33],
        ['smart', 'Smart', 34, 40],
    ]);
});

it('seeds 48 recommendation lines and 45 mappings to existing catalog services without adding any service', function () {
    $this->seed(ReferenceDataSeeder::class);

    $perCategory = ReadinessCategory::query()->withCount('recommendations')->orderBy('sort_order')->pluck('recommendations_count', 'code');

    expect($perCategory->all())->toBe(['b4_automation' => 11, 'basic' => 8, 'advanced' => 15, 'smart' => 14])
        ->and(DB::table('catalog_service_readiness_recommendation')->count())->toBe(45)
        ->and(CatalogService::query()->count())->toBe(42);
});

it('can run repeatedly without duplicating or replacing any readiness row or mapping', function () {
    $this->seed(ReferenceDataSeeder::class);
    $rowsAfterFirstRun = readinessReferenceRows();

    $this->seed(ReferenceDataSeeder::class);

    expect(readinessReferenceRows())->toBe($rowsAfterFirstRun);
});

it('restores edited source text and mappings on a re-run', function () {
    $this->seed(ReferenceDataSeeder::class);
    ReadinessQuestion::query()->where('code', 'q1')->update(['text_ar' => 'نص معدل']);
    $line = ReadinessRecommendation::query()->whereHas('services', fn ($services) => $services->where('code', 'automation_ot.01'))->sole();
    $line->services()->sync(CatalogService::query()->where('code', 'automation_ot.02')->pluck('id'));

    $this->seed(ReferenceDataSeeder::class);

    expect(ReadinessQuestion::query()->where('code', 'q1')->value('text_ar'))->toBe('كيف تصف استراتيجية التحول الرقمي في مؤسستك حالياً؟')
        ->and($line->services()->pluck('code')->all())->toBe(['automation_ot.01']);
});

it('keeps completed assessments and legacy manual classifications when the reference data is seeded again', function () {
    $this->seed(ReferenceDataSeeder::class);
    $factory = Factory::factory()->create();
    $assessment = storedReadinessAssessment($factory, 'c');
    $legacy = new FactoryAssessment;
    $legacy->factory_id = $factory->id;
    $legacy->maturity_tier_id = MaturityTier::query()->where('code', 'basic_dx')->value('id');
    $legacy->justification = 'Recorded before the readiness questionnaire existed.';
    $legacy->assessed_on = now();
    $legacy->recorded_by_user_id = User::factory()->imcAdmin()->create()->id;
    $legacy->save();

    $this->seed(ReferenceDataSeeder::class);

    expect(ReadinessAssessment::query()->sole()->only(['id', 'total_score', 'readiness_category_id']))->toBe($assessment->only(['id', 'total_score', 'readiness_category_id']))
        ->and(ReadinessAssessmentAnswer::query()->count())->toBe(10)
        ->and(FactoryAssessment::query()->sole()->id)->toBe($legacy->id);
});

it('refuses to change the points or thresholds of a version that has been answered', function (Closure $tamper) {
    $this->seed(ReferenceDataSeeder::class);
    storedReadinessAssessment(Factory::factory()->create(), 'b');
    $tamper();

    expect(fn () => $this->seed(ReadinessAssessmentSeeder::class))
        ->toThrow(RuntimeException::class, 'Readiness questionnaire version 1 has assessments');
})->with([
    'points of a choice' => [fn () => ReadinessChoice::query()->where('code', 'a')->limit(1)->update(['points' => 2])],
    'a category threshold' => [fn () => ReadinessCategory::query()->where('code', 'basic')->update(['min_score' => 19])],
]);

it('seeds the questionnaire through the default seeder in production', function () {
    $this->app['env'] = 'production';

    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

    expect(ReadinessQuestionnaire::query()->where('is_current', true)->count())->toBe(1)
        ->and(ReadinessQuestion::query()->count())->toBe(10)
        ->and(ReadinessAssessment::query()->count())->toBe(0);
});
