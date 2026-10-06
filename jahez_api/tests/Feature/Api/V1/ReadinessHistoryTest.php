<?php

use App\Models\Factory;
use App\Models\ReadinessAssessment;
use App\Models\ReadinessChoice;
use App\Models\ReadinessQuestion;
use App\Models\ReadinessQuestionnaire;
use App\Models\User;
use App\Readiness\ReadinessAssessmentRecorder;
use Database\Seeders\ReferenceDataSeeder;
use Laravel\Sanctum\Sanctum;

/*
 * Historical integrity of readiness assessments and the IMC result filters
 * (ADR-018 addendum 2).
 */

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

it('keeps the text an answer was given with when the version text is corrected later', function () {
    $factory = Factory::factory()->create();
    Sanctum::actingAs(User::factory()->factoryMember($factory)->create());
    $assessmentId = $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('b'))
        ->assertCreated()
        ->json('data.id');
    $question = ReadinessQuestion::query()->where('code', 'q1')->sole();
    $choice = ReadinessChoice::query()->where('readiness_question_id', $question->id)->where('code', 'b')->sole();
    $originalQuestion = $question->text_ar;
    $originalChoice = $choice->text_ar;

    // A proofreading correction applied in place by the reference-data seeder (OQ-32).
    ReadinessQuestion::query()->whereKey($question->id)->update(['text_ar' => 'نص مصحح لاحقًا']);
    ReadinessChoice::query()->whereKey($choice->id)->update(['text_ar' => 'اختيار مصحح لاحقًا', 'label_ar' => 'ب*']);

    $this->getJson(route('api.v1.factories.readiness-assessments.show', [$factory, $assessmentId]))
        ->assertOk()
        ->assertJsonPath('data.answers.0.question_text_ar', $originalQuestion)
        ->assertJsonPath('data.answers.0.choice_label_ar', 'ب');

    Sanctum::actingAs(User::factory()->imcAdmin()->create());
    $this->getJson(route('api.v1.factories.readiness-assessments.show', [$factory, $assessmentId]))
        ->assertOk()
        ->assertJsonPath('data.total_score', 20)
        ->assertJsonPath('data.category.code', 'basic')
        ->assertJsonPath('data.answers.0.question_text_ar', $originalQuestion)
        ->assertJsonPath('data.answers.0.choice_text_ar', $originalChoice)
        ->assertJsonPath('data.answers.0.choice_label_ar', 'ب')
        ->assertJsonPath('data.answers.0.points', 2);
});

it('keeps old results unchanged when a reworded version is published', function () {
    $factory = Factory::factory()->create();
    $earlier = storedReadinessAssessment($factory, readinessChoicesForTotal(33));
    Sanctum::actingAs(User::factory()->imcAdmin()->create());
    $draft = $this->postJson(route('api.v1.readiness-questionnaires.store'))->json('data');
    $definition = $this->getJson(route('api.v1.readiness-questionnaires.show', $draft['id']))->json('data');
    $payload = [
        'title_ar' => $definition['title_ar'],
        'pillars' => array_map(fn (array $pillar): array => [
            'code' => $pillar['code'],
            'name_ar' => $pillar['name_ar'],
            'questions' => array_map(fn (array $question): array => [
                'code' => $question['code'],
                'text_ar' => 'صياغة جديدة: '.$question['text_ar'],
                'choices' => array_map(fn (array $choice): array => array_intersect_key($choice, array_flip(['code', 'label_ar', 'text_ar', 'points'])), $question['choices']),
            ], $pillar['questions']),
        ], $definition['definition']['pillars']),
        'categories' => array_map(fn (array $category): array => array_intersect_key($category, array_flip(['code', 'name_ar', 'name_en', 'description_ar', 'min_score', 'max_score', 'focus_ar', 'steps_ar'])), $definition['definition']['categories']),
    ];
    // Move the Advanced band so 33 would now be Smart under version 2.
    $payload['categories'][2]['max_score'] = 32;
    $payload['categories'][3]['min_score'] = 33;
    $this->putJson(route('api.v1.readiness-questionnaires.update', $draft['id']), $payload)->assertOk();
    $this->postJson(route('api.v1.readiness-questionnaires.publish', $draft['id']))->assertOk();

    $this->getJson(route('api.v1.factories.readiness-assessments.show', [$factory, $earlier]))
        ->assertJsonPath('data.questionnaire_version', 1)
        ->assertJsonPath('data.total_score', 33)
        ->assertJsonPath('data.category.code', 'advanced')
        ->assertJsonPath('data.category.max_score', 33);
    expect(ReadinessAssessment::query()->sole()->total_score)->toBe(33);
});

it('filters the IMC result list by score range and factory', function () {
    $factories = collect([12, 22, 30, 38])->mapWithKeys(function (int $total): array {
        $factory = Factory::factory()->create();
        storedReadinessAssessment($factory, readinessChoicesForTotal($total));

        return [$total => $factory];
    });
    Sanctum::actingAs(User::factory()->imcAdmin()->create());

    $totals = $this->getJson(route('api.v1.readiness-assessments.index', ['filter' => ['score_min' => 20, 'score_max' => 31], 'sort' => 'score_asc']))
        ->assertOk()
        ->json('data.*.total_score');
    expect($totals)->toBe([22, 30]);

    $this->getJson(route('api.v1.readiness-assessments.index', ['filter' => ['factory' => $factories[38]->id]]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.total_score', 38)
        ->assertJsonPath('data.0.category.code', 'smart');

    $this->getJson(route('api.v1.readiness-assessments.index', ['filter' => ['score_min' => 30, 'score_max' => 20]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.score_max']);
    $this->getJson(route('api.v1.readiness-assessments.index', ['filter' => ['points' => 3]]))
        ->assertUnprocessable();
});

it('does not let a factory member read the results of another factory', function () {
    $other = Factory::factory()->create();
    $assessment = storedReadinessAssessment($other, 'c');
    Sanctum::actingAs(User::factory()->factoryMember()->create());

    $this->getJson(route('api.v1.factories.readiness-assessments.show', [$other, $assessment]))->assertNotFound();
    $this->getJson(route('api.v1.readiness-assessments.index'))->assertForbidden();
});

describe('ReadinessAssessmentRecorder', function () {
    it('scores and classifies from the stored points and keeps the text', function () {
        $factory = Factory::factory()->create();
        $questionnaire = ReadinessQuestionnaire::current()?->load('questions.choices');
        $choices = $questionnaire->questions->map(fn (ReadinessQuestion $question): ReadinessChoice => $question->choices->firstWhere('code', 'd'))->values()->all();

        ['assessment' => $assessment, 'created' => $created] = app(ReadinessAssessmentRecorder::class)
            ->record($factory, $questionnaire, $choices, User::factory()->factoryMember($factory)->create());

        expect($created)->toBeTrue()
            ->and($assessment->total_score)->toBe(40)
            ->and($assessment->category->code->value)->toBe('smart')
            ->and($assessment->answers()->whereNull('question_text_ar')->count())->toBe(0);
    });

    it('refuses a set of choices that does not answer every question once', function () {
        $factory = Factory::factory()->create();
        $questionnaire = ReadinessQuestionnaire::current()?->load('questions.choices');
        $choices = $questionnaire->questions->map(fn (ReadinessQuestion $question): ReadinessChoice => $question->choices->first())->values()->all();
        array_pop($choices);

        app(ReadinessAssessmentRecorder::class)->record($factory, $questionnaire, $choices, User::factory()->factoryMember($factory)->create());
    })->throws(InvalidArgumentException::class);

    it('refuses a version that is not the current one', function () {
        $factory = Factory::factory()->create();
        $questionnaire = ReadinessQuestionnaire::current()?->load('questions.choices');
        $questionnaire->is_current = null;

        app(ReadinessAssessmentRecorder::class)->record($factory, $questionnaire, [], User::factory()->factoryMember($factory)->create());
    })->throws(LogicException::class);
});
