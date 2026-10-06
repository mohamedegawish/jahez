<?php

use App\Http\Requests\Api\V1\StoreReadinessAssessmentRequest;
use App\Models\AuditLog;
use App\Models\Factory;
use App\Models\ReadinessAssessment;
use App\Models\ReadinessAssessmentAnswer;
use App\Models\ReadinessCategory;
use App\Models\ReadinessChoice;
use App\Models\ReadinessPillar;
use App\Models\ReadinessQuestion;
use App\Models\ReadinessQuestionnaire;
use App\Models\ServiceProvider;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

/**
 * A factory and one of its members, signed in.
 *
 * @return array{Factory, User}
 */
function signedInFactoryMember(): array
{
    $factory = Factory::factory()->create();
    $member = User::factory()->factoryMember($factory)->create(['name' => 'Factory Member']);
    Sanctum::actingAs($member);

    return [$factory, $member];
}

/**
 * The total the server stored for the factory's latest assessment. A factory member's
 * response carries the category, never the score (ADR-026).
 */
function storedReadinessTotal(Factory $factory): ?int
{
    return ReadinessAssessment::query()->where('factory_id', $factory->id)->latest('id')->value('total_score');
}

/**
 * Sign in as an IMC administrator, who sees readiness scores.
 */
function signInAsImc(): User
{
    $admin = User::factory()->imcAdmin()->create();
    Sanctum::actingAs($admin);

    return $admin;
}

/**
 * Publish a version 2 with the same structure as version 1 and make it current, the
 * way a later change of the questionnaire would be introduced.
 */
function publishReadinessVersionTwo(): ReadinessQuestionnaire
{
    $first = ReadinessQuestionnaire::query()->where('version', 1)->with(['pillars.questions.choices', 'categories'])->sole();
    $second = ReadinessQuestionnaire::query()->create(['version' => 2, 'title_ar' => $first->title_ar, 'title_en' => $first->title_en, 'source_ref' => 'test version 2']);

    foreach ($first->pillars as $pillar) {
        $newPillar = ReadinessPillar::query()->create([...$pillar->only(['code', 'name_ar', 'name_en', 'sort_order']), 'readiness_questionnaire_id' => $second->id]);
        foreach ($pillar->questions as $question) {
            $newQuestion = ReadinessQuestion::query()->create([...$question->only(['code', 'number', 'text_ar', 'source_ref']), 'readiness_questionnaire_id' => $second->id, 'readiness_pillar_id' => $newPillar->id]);
            foreach ($question->choices as $choice) {
                ReadinessChoice::query()->create([...$choice->only(['code', 'label_ar', 'text_ar', 'points', 'sort_order']), 'readiness_question_id' => $newQuestion->id]);
            }
        }
    }
    foreach ($first->categories as $category) {
        ReadinessCategory::query()->create([...$category->only(['code', 'name_en', 'name_ar', 'description_ar', 'min_score', 'max_score', 'focus_ar', 'steps_ar', 'sort_order']), 'readiness_questionnaire_id' => $second->id]);
    }

    ReadinessQuestionnaire::query()->whereKey($first->id)->update(['is_current' => null]);
    ReadinessQuestionnaire::query()->whereKey($second->id)->update(['is_current' => true]);

    return $second;
}

describe('scoring and classification', function () {
    it('scores all «أ» answers 10 and classifies the factory as B4 Automation', function () {
        $this->travelTo('2026-10-03 10:00:00');
        [$factory, $member] = signedInFactoryMember();

        $response = $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('a'));

        $response->assertCreated()
            ->assertJsonPath('data.factory_id', $factory->id)
            ->assertJsonPath('data.questionnaire_version', 1)
            ->assertJsonPath('data.category.code', 'b4_automation')
            ->assertJsonPath('data.category.name_ar', 'ما قبل الأتمتة')
            ->assertJsonPath('data.submitted_by', ['id' => $member->id, 'name' => 'Factory Member'])
            ->assertJsonPath('data.completed_at', '2026-10-03T10:00:00Z')
            ->assertJsonCount(10, 'data.answers');
        $this->assertDatabaseHas('readiness_assessments', ['factory_id' => $factory->id, 'total_score' => 10, 'submitted_by_user_id' => $member->id]);
        expect(ReadinessAssessmentAnswer::query()->sum('points'))->toEqual(10);
    });

    it('scores all «د» answers 40 and classifies the factory as Smart', function () {
        [$factory] = signedInFactoryMember();

        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('d'))
            ->assertCreated()
            ->assertJsonPath('data.category.code', 'smart');
        expect(storedReadinessTotal($factory))->toBe(40);
    });

    it('sums mixed answers and breaks the score down by pillar', function () {
        [$factory] = signedInFactoryMember();

        $id = $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload(['a', 'b', 'c', 'd', 'a', 'b', 'c', 'd', 'a', 'b']))
            ->assertCreated()
            ->assertJsonPath('data.category.code', 'basic')
            ->json('data.id');

        signInAsImc();
        $response = $this->getJson(route('api.v1.factories.readiness-assessments.show', [$factory, $id]))
            ->assertOk()
            ->assertJsonPath('data.total_score', 23)
            ->assertJsonPath('data.min_score', 10)
            ->assertJsonPath('data.max_score', 40);
        expect(array_map(fn (array $pillar): array => [$pillar['code'], $pillar['score'], $pillar['max_score']], $response->json('data.pillars')))->toBe([
            ['strategy_leadership', 3, 8],
            ['processes_operations', 7, 8],
            ['technology_data', 3, 8],
            ['culture_people', 7, 8],
            ['customer_experience', 3, 8],
        ])
            ->and($response->json('data.answers.3'))->toMatchArray(['question_code' => 'q4', 'question_number' => 4, 'choice_code' => 'd', 'choice_label_ar' => 'د', 'points' => 4]);
    });

    it('classifies the totals on either side of each threshold', function (int $total, string $category) {
        [$factory] = signedInFactoryMember();

        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload(readinessChoicesForTotal($total)))
            ->assertCreated()
            ->assertJsonPath('data.category.code', $category);
        expect(storedReadinessTotal($factory))->toBe($total);
    })->with([
        [17, 'b4_automation'],
        [18, 'basic'],
        [25, 'basic'],
        [26, 'advanced'],
        [33, 'advanced'],
        [34, 'smart'],
    ]);

    it('ignores a score, category or points the client sends', function () {
        [$factory] = signedInFactoryMember();
        $payload = readinessPayload('a', ['total_score' => 40, 'score' => 40, 'category' => 'smart', 'readiness_level' => 'smart']);
        $payload['answers'] = array_map(fn (array $answer): array => [...$answer, 'points' => 4], $payload['answers']);

        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), $payload)
            ->assertCreated()
            ->assertJsonPath('data.category.code', 'b4_automation');
        expect(storedReadinessTotal($factory))->toBe(10)
            ->and(ReadinessAssessmentAnswer::query()->sum('points'))->toEqual(10);
    });

    it('returns the roadmap of the category with the catalog services it recommends', function () {
        [$factory] = signedInFactoryMember();

        $response = $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('b'));

        $response->assertCreated()
            ->assertJsonPath('data.category.code', 'basic')
            ->assertJsonPath('data.category.roadmap.focus_ar', 'الربط والأتمتة البسيطة.')
            ->assertJsonCount(8, 'data.category.roadmap.recommendations')
            ->assertJsonPath('data.category.roadmap.recommendations.0.services.0.code', 'automation_ot.01')
            ->assertJsonPath('data.category.roadmap.recommendations.5.text_ar', 'تقييم البنية التحتية')
            ->assertJsonPath('data.category.roadmap.recommendations.5.services', []);
    });
});

describe('validation', function () {
    it('rejects a submission that leaves a question unanswered, and stores nothing', function () {
        [$factory] = signedInFactoryMember();
        $payload = readinessPayload('a');
        array_pop($payload['answers']);

        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['answers' => str_replace(':size', '10', StoreReadinessAssessmentRequest::ANSWER_EVERY_QUESTION)]);
        $this->assertDatabaseCount('readiness_assessments', 0);
        $this->assertDatabaseCount('readiness_assessment_answers', 0);
    });

    it('rejects two answers to the same question', function () {
        [$factory] = signedInFactoryMember();
        $payload = readinessPayload('a');
        $payload['answers'][9] = $payload['answers'][0];

        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.9.question_id' => StoreReadinessAssessmentRequest::QUESTION_ANSWERED_TWICE]);
        $this->assertDatabaseCount('readiness_assessments', 0);
    });

    it('rejects a choice that belongs to another question', function () {
        [$factory] = signedInFactoryMember();
        $payload = readinessPayload('a');
        $payload['answers'][0]['choice_id'] = $payload['answers'][1]['choice_id'];

        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.0.choice_id' => StoreReadinessAssessmentRequest::CHOICE_OF_ANOTHER_QUESTION]);
        $this->assertDatabaseCount('readiness_assessments', 0);
    });

    it('rejects a question that is not in the current questionnaire', function () {
        [$factory] = signedInFactoryMember();
        $payload = readinessPayload('a');
        $payload['answers'][2]['question_id'] = ReadinessQuestion::query()->max('id') + 1;

        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.2.question_id']);
        $this->assertDatabaseCount('readiness_assessments', 0);
    });

    it('rejects answers to a questionnaire version that is no longer current', function () {
        [$factory] = signedInFactoryMember();
        $staleVersionOne = readinessPayload('a');
        publishReadinessVersionTwo();

        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), $staleVersionOne)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['questionnaire_version' => StoreReadinessAssessmentRequest::STALE_VERSION]);
        $this->assertDatabaseCount('readiness_assessments', 0);
    });

    it('answers 409 when no questionnaire is current', function () {
        [$factory] = signedInFactoryMember();
        $payload = readinessPayload('a');
        ReadinessQuestionnaire::query()->update(['is_current' => null]);

        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), $payload)
            ->assertConflict()
            ->assertJsonPath('message', 'No readiness questionnaire is available.');
        $this->assertDatabaseCount('readiness_assessments', 0);
    });

    it('requires the version and the answers', function () {
        [$factory] = signedInFactoryMember();

        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['questionnaire_version', 'answers']);
    });

    it('rejects a choice id that does not exist', function () {
        [$factory] = signedInFactoryMember();
        $payload = readinessPayload('a');
        $payload['answers'][4]['choice_id'] = ReadinessChoice::query()->max('id') + 1;

        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.4.choice_id' => StoreReadinessAssessmentRequest::CHOICE_OF_ANOTHER_QUESTION]);
        $this->assertDatabaseCount('readiness_assessments', 0);
    });

    it('rejects answers that are not ids', function () {
        [$factory] = signedInFactoryMember();
        $payload = readinessPayload('a');
        $payload['answers'][0] = ['question_id' => 'q1', 'choice_id' => 'a'];

        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.0.question_id', 'answers.0.choice_id']);
    });
});

describe('duplicate submissions', function () {
    it('stores a submission once when it is repeated with the same Idempotency-Key', function () {
        [$factory] = signedInFactoryMember();
        $headers = ['Idempotency-Key' => 'assessment-attempt-0001'];

        $first = $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('c'), $headers)->assertCreated();
        $repeat = $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('c'), $headers)->assertOk();

        expect($repeat->json('data.id'))->toBe($first->json('data.id'))
            ->and($repeat->json('data.category.code'))->toBe('advanced')
            ->and(storedReadinessTotal($factory))->toBe(30);
        $this->assertDatabaseCount('readiness_assessments', 1);
        $this->assertDatabaseCount('readiness_assessment_answers', 10);
        expect(AuditLog::query()->where('event', 'factory.readiness_assessment_completed')->count())->toBe(1);

        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('d'), ['Idempotency-Key' => 'assessment-attempt-0002'])->assertCreated();
        $this->assertDatabaseCount('readiness_assessments', 2);
    });

    it('scopes the key to the factory', function () {
        [$factory] = signedInFactoryMember();
        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('a'), ['Idempotency-Key' => 'shared-key-0001'])->assertCreated();

        [$otherFactory] = signedInFactoryMember();
        $this->postJson(route('api.v1.factories.readiness-assessments.store', $otherFactory), readinessPayload('b'), ['Idempotency-Key' => 'shared-key-0001'])
            ->assertCreated()
            ->assertJsonPath('data.factory_id', $otherFactory->id);
    });

    it('rejects a malformed key', function () {
        [$factory] = signedInFactoryMember();

        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('a'), ['Idempotency-Key' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key']);
        $this->assertDatabaseCount('readiness_assessments', 0);
    });
});

describe('access', function () {
    it('lets IMC administrators read every factory\'s results but not submit one', function () {
        $factory = Factory::factory()->create();
        $assessment = storedReadinessAssessment($factory, 'c');
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->getJson(route('api.v1.factories.readiness-assessments.index', $factory))->assertOk()->assertJsonPath('data.0.id', $assessment->id);
        $this->getJson(route('api.v1.factories.readiness-assessments.show', [$factory, $assessment]))->assertOk()->assertJsonPath('data.total_score', 30);
        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('a'))->assertForbidden();
        $this->assertDatabaseCount('readiness_assessments', 1);
    });

    it('shows a factory member its level and answers but never a score, a range or points', function () {
        [$factory] = signedInFactoryMember();
        $id = $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload(['a', 'b', 'c', 'd', 'a', 'b', 'c', 'd', 'a', 'b']))->assertCreated()->json('data.id');

        foreach ([
            $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('b'))->assertCreated(),
            $this->getJson(route('api.v1.factories.readiness-assessments.show', [$factory, $id]))->assertOk(),
        ] as $response) {
            $response->assertJsonPath('data.category.code', 'basic')
                ->assertJsonMissingPath('data.total_score')
                ->assertJsonMissingPath('data.min_score')
                ->assertJsonMissingPath('data.max_score')
                ->assertJsonMissingPath('data.pillars')
                ->assertJsonMissingPath('data.category.min_score')
                ->assertJsonMissingPath('data.category.max_score')
                ->assertJsonCount(10, 'data.answers');
            expect(collect($response->json('data.answers'))->every(fn (array $answer): bool => ! array_key_exists('points', $answer) && $answer['choice_label_ar'] !== null))->toBeTrue();
        }

        $this->getJson(route('api.v1.factories.readiness-assessments.index', $factory))
            ->assertOk()
            ->assertJsonMissingPath('data.0.total_score')
            ->assertJsonMissingPath('data.0.category.min_score');
        $this->getJson(route('api.v1.factories.show', $factory))
            ->assertJsonPath('data.current_readiness.category.code', 'basic')
            ->assertJsonMissingPath('data.current_readiness.total_score');
    });

    it('returns 404 to anyone outside the factory and IMC, before validating the payload', function (Closure $makeOutsider) {
        $factory = Factory::factory()->create();
        $assessment = storedReadinessAssessment($factory, 'a');
        Sanctum::actingAs($makeOutsider());

        $this->getJson(route('api.v1.factories.readiness-assessments.index', $factory))->assertNotFound();
        $this->getJson(route('api.v1.factories.readiness-assessments.show', [$factory, $assessment]))->assertNotFound();
        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), [])->assertNotFound();
        $this->assertDatabaseCount('readiness_assessments', 1);
    })->with([
        'member of another factory' => [fn () => User::factory()->factoryMember()->create()],
        'provider member' => [fn () => User::factory()->providerMember(ServiceProvider::factory()->create())->create()],
    ]);

    it('returns 404 for an assessment requested under another factory', function () {
        $otherFactoryAssessment = storedReadinessAssessment(Factory::factory()->create(), 'a');
        $factory = Factory::factory()->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->getJson(route('api.v1.factories.readiness-assessments.show', [$factory, $otherFactoryAssessment]))->assertNotFound();
    });

    it('returns 401 without a token', function () {
        $factory = Factory::factory()->create();

        $this->getJson(route('api.v1.factories.readiness-assessments.index', $factory))->assertUnauthorized();
        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('a'))->assertUnauthorized();
    });
});

describe('history', function () {
    it('keeps every assessment, lists the latest first and makes it the current classification', function () {
        [$factory] = signedInFactoryMember();
        $this->travelTo('2026-09-01 08:00:00');
        $first = $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('a'))->json('data.id');
        $this->travelTo('2026-10-01 08:00:00');
        $second = $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('c'))->json('data.id');

        $history = $this->getJson(route('api.v1.factories.readiness-assessments.index', $factory));

        $history->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissingPath('data.0.answers');
        expect($history->json('data.*.id'))->toBe([$second, $first])
            ->and($history->json('data.*.category.code'))->toBe(['advanced', 'b4_automation']);
        $this->getJson(route('api.v1.factories.show', $factory))
            ->assertJsonPath('data.current_readiness.assessment_id', $second)
            ->assertJsonPath('data.current_readiness.category.code', 'advanced');
        $this->getJson(route('api.v1.factories.readiness-assessments.show', [$factory, $first]))
            ->assertOk()
            ->assertJsonPath('data.category.code', 'b4_automation');

        signInAsImc();
        $this->getJson(route('api.v1.factories.show', $factory))->assertJsonPath('data.current_readiness.total_score', 30);
        $this->getJson(route('api.v1.factories.readiness-assessments.show', [$factory, $first]))->assertJsonPath('data.total_score', 10);
    });

    it('keeps an assessment with the version it answered after a new version becomes current', function () {
        [$factory] = signedInFactoryMember();
        $first = $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('b'))->json('data.id');

        publishReadinessVersionTwo();
        $second = $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('d'))->assertCreated();

        $second->assertJsonPath('data.questionnaire_version', 2);
        signInAsImc();
        $this->getJson(route('api.v1.factories.readiness-assessments.show', [$factory, $first]))
            ->assertJsonPath('data.questionnaire_version', 1)
            ->assertJsonPath('data.total_score', 20)
            ->assertJsonPath('data.category.code', 'basic');
    });

    it('leaves no assessment, answer or audit entry when saving fails midway', function () {
        [$factory] = signedInFactoryMember();
        $saved = 0;
        ReadinessAssessmentAnswer::created(function () use (&$saved): void {
            if (++$saved === 5) {
                throw new RuntimeException('Simulated failure while saving the answers.');
            }
        });

        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('a'))->assertServerError();

        $this->assertDatabaseCount('readiness_assessments', 0);
        $this->assertDatabaseCount('readiness_assessment_answers', 0);
        expect(AuditLog::query()->where('event', 'factory.readiness_assessment_completed')->count())->toBe(0);
    });
});
