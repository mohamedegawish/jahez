<?php

use App\Enums\AuditEvent;
use App\Http\Controllers\Api\V1\ReadinessQuestionnaireVersionController;
use App\Models\AuditLog;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\ReadinessAssessment;
use App\Models\ReadinessChoice;
use App\Models\ReadinessQuestion;
use App\Models\ReadinessQuestionnaire;
use App\Models\ReadinessRecommendation;
use App\Models\ServiceProvider;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

/**
 * The definition of a version as the update endpoint takes it, read from the admin view.
 *
 * @param  array<string, mixed>  $version  `data` of the admin resource
 * @return array<string, mixed>
 */
function questionnaireDefinition(array $version): array
{
    return [
        'title_ar' => $version['title_ar'],
        'title_en' => $version['title_en'],
        'pillars' => array_map(fn (array $pillar): array => [
            'code' => $pillar['code'],
            'name_ar' => $pillar['name_ar'],
            'name_en' => $pillar['name_en'],
            'questions' => array_map(fn (array $question): array => [
                'code' => $question['code'],
                'text_ar' => $question['text_ar'],
                'choices' => array_map(fn (array $choice): array => array_intersect_key($choice, array_flip(['code', 'label_ar', 'text_ar', 'points'])), $question['choices']),
            ], $pillar['questions']),
        ], $version['definition']['pillars']),
        'categories' => array_map(fn (array $category): array => array_intersect_key($category, array_flip(['code', 'name_ar', 'name_en', 'description_ar', 'min_score', 'max_score', 'focus_ar', 'steps_ar'])), $version['definition']['categories']),
    ];
}

/**
 * Signs in an IMC administrator and creates a draft from the current version.
 *
 * @return array<string, mixed> `data` of the draft
 */
function readinessDraft(): array
{
    Sanctum::actingAs(User::factory()->imcAdmin()->create());

    return test()->postJson(route('api.v1.readiness-questionnaires.store'))->assertCreated()->json('data');
}

it('lists the versions with their status for IMC administrators', function () {
    Sanctum::actingAs(User::factory()->imcAdmin()->create());

    $this->getJson(route('api.v1.readiness-questionnaires.index'))
        ->assertOk()
        ->assertJsonPath('data.0.version', 1)
        ->assertJsonPath('data.0.status', 'current')
        ->assertJsonPath('data.0.assessments_count', 0);
});

it('creates a draft that copies the current version, its scoring and its roadmaps', function () {
    $draft = readinessDraft();

    expect($draft['version'])->toBe(2)
        ->and($draft['status'])->toBe('draft')
        ->and($draft['published_at'])->toBeNull()
        ->and($draft['definition']['min_score'])->toBe(10)
        ->and($draft['definition']['max_score'])->toBe(40)
        ->and(array_column($draft['definition']['categories'], 'min_score'))->toBe([10, 18, 26, 34])
        ->and(count($draft['definition']['categories'][0]['roadmap']['recommendations']))->toBeGreaterThan(0);
    $this->getJson(route('api.v1.readiness-questionnaire.show'))->assertJsonPath('data.version', 1);

    $this->postJson(route('api.v1.readiness-questionnaires.store'))
        ->assertConflict()
        ->assertJsonPath('message', ReadinessQuestionnaireVersionController::ONE_DRAFT);
    expect(AuditLog::query()->where('event', AuditEvent::ReadinessQuestionnaireDrafted)->sole()->metadata)->toEqual(['version' => 2, 'based_on_version' => 1]);
});

it('edits a draft in place, keeping the ids of the rows it keeps', function () {
    $draft = readinessDraft();
    $definition = questionnaireDefinition($draft);
    $firstQuestionId = $draft['definition']['pillars'][0]['questions'][0]['id'];
    // Swap the two questions of the first pillar and reword one choice.
    $definition['pillars'][0]['questions'] = array_reverse($definition['pillars'][0]['questions']);
    $definition['pillars'][0]['questions'][1]['choices'][0]['text_ar'] = 'نص معدل للاختيار';

    $response = $this->putJson(route('api.v1.readiness-questionnaires.update', $draft['id']), $definition)->assertOk();

    expect($response->json('data.definition.pillars.0.questions.*.code'))->toBe(['q2', 'q1'])
        ->and($response->json('data.definition.pillars.0.questions.1.id'))->toBe($firstQuestionId)
        ->and($response->json('data.definition.pillars.0.questions.1.number'))->toBe(2)
        ->and($response->json('data.definition.pillars.0.questions.1.choices.0.text_ar'))->toBe('نص معدل للاختيار');
    // Version 1 is untouched.
    expect(ReadinessQuestion::query()->where('readiness_questionnaire_id', ReadinessQuestionnaire::query()->where('version', 1)->value('id'))->where('code', 'q1')->value('number'))->toBe(1);
});

it('accepts reordered choices, new labels and new category bounds within the source scale', function () {
    $draft = readinessDraft();
    $definition = questionnaireDefinition($draft);
    // Show the highest-scoring choice first in question 1, with new labels.
    $definition['pillars'][0]['questions'][0]['choices'] = array_reverse($definition['pillars'][0]['questions'][0]['choices']);
    foreach ($definition['pillars'][0]['questions'][0]['choices'] as $index => &$choice) {
        $choice['label_ar'] = (string) ($index + 1);
    }
    unset($choice);
    $definition['categories'][0]['max_score'] = 16;
    $definition['categories'][1]['min_score'] = 17;

    $response = $this->putJson(route('api.v1.readiness-questionnaires.update', $draft['id']), $definition)
        ->assertOk()
        ->assertJsonPath('data.definition.min_score', 10)
        ->assertJsonPath('data.definition.max_score', 40);

    expect($response->json('data.definition.pillars.0.questions.0.choices.*.code'))->toBe(['d', 'c', 'b', 'a'])
        ->and($response->json('data.definition.pillars.0.questions.0.choices.*.points'))->toBe([4, 3, 2, 1])
        ->and($response->json('data.definition.pillars.0.questions.0.choices.*.label_ar'))->toBe(['1', '2', '3', '4'])
        ->and(array_column($response->json('data.definition.categories'), 'max_score'))->toBe([16, 25, 33, 40]);
});

it('refuses category ranges that leave a gap, overlap or miss the possible totals', function (Closure $tamper) {
    $draft = readinessDraft();
    $definition = questionnaireDefinition($draft);
    $tamper($definition);

    $this->putJson(route('api.v1.readiness-questionnaires.update', $draft['id']), $definition)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['categories']);
})->with([
    'a gap' => [function (array &$definition): void {
        $definition['categories'][1]['min_score'] = 19;
    }],
    'an overlap' => [function (array &$definition): void {
        $definition['categories'][1]['min_score'] = 17;
    }],
    'the top range ends early' => [function (array &$definition): void {
        $definition['categories'][3]['max_score'] = 39;
    }],
]);

it('refuses duplicate codes, unknown categories and a question without its four choices', function (Closure $tamper, string $field) {
    $draft = readinessDraft();
    $definition = questionnaireDefinition($draft);
    $tamper($definition);

    $this->putJson(route('api.v1.readiness-questionnaires.update', $draft['id']), $definition)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'a repeated question code' => [function (array &$definition): void {
        $definition['pillars'][1]['questions'][0]['code'] = 'q1';
    }, 'pillars.1.questions.0.code'],
    'a repeated choice code' => [function (array &$definition): void {
        $definition['pillars'][0]['questions'][0]['choices'][1]['code'] = 'a';
    }, 'pillars.0.questions.0.choices'],
    'a new category' => [function (array &$definition): void {
        $definition['categories'][3]['code'] = 'genius';
    }, 'categories.3.code'],
    'a single choice' => [function (array &$definition): void {
        $definition['pillars'][0]['questions'][0]['choices'] = [$definition['pillars'][0]['questions'][0]['choices'][0]];
    }, 'pillars.0.questions.0.choices'],
]);

it('publishes a draft, after which factories answer it and earlier results keep their version and score', function () {
    $factory = Factory::factory()->create();
    $earlier = storedReadinessAssessment($factory, 'b');
    $draft = readinessDraft();

    $this->postJson(route('api.v1.readiness-questionnaires.publish', $draft['id']))
        ->assertOk()
        ->assertJsonPath('data.status', 'current');
    $this->getJson(route('api.v1.readiness-questionnaires.index'))
        ->assertJsonPath('data.0.status', 'current')
        ->assertJsonPath('data.1.status', 'retired')
        ->assertJsonPath('data.1.assessments_count', 1);

    Sanctum::actingAs(User::factory()->factoryMember($factory)->create());
    $this->getJson(route('api.v1.readiness-questionnaire.show'))->assertJsonPath('data.version', 2);
    $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('d'))
        ->assertCreated()
        ->assertJsonPath('data.questionnaire_version', 2);
    $this->getJson(route('api.v1.factories.readiness-assessments.show', [$factory, $earlier]))
        ->assertJsonPath('data.questionnaire_version', 1)
        ->assertJsonPath('data.total_score', 20)
        ->assertJsonPath('data.category.code', 'basic');

    expect(AuditLog::query()->where('event', AuditEvent::ReadinessQuestionnairePublished)->sole()->metadata)->toEqual(['version' => 2, 'previous_version' => 1]);
});

it('never changes or deletes a published version', function () {
    Sanctum::actingAs(User::factory()->imcAdmin()->create());
    $current = ReadinessQuestionnaire::query()->where('is_current', true)->sole();
    $definition = questionnaireDefinition($this->getJson(route('api.v1.readiness-questionnaires.show', $current))->json('data'));

    $this->putJson(route('api.v1.readiness-questionnaires.update', $current), $definition)->assertConflict();
    $this->postJson(route('api.v1.readiness-questionnaires.publish', $current))->assertConflict();
    $this->deleteJson(route('api.v1.readiness-questionnaires.destroy', $current))->assertConflict();
    $this->assertDatabaseHas('readiness_questionnaires', ['id' => $current->id, 'is_current' => true]);
});

it('deletes a draft', function () {
    $draft = readinessDraft();

    $this->deleteJson(route('api.v1.readiness-questionnaires.destroy', $draft['id']))->assertNoContent();

    expect(ReadinessQuestionnaire::query()->pluck('version')->all())->toBe([1]);
    $this->postJson(route('api.v1.readiness-questionnaires.store'))->assertCreated()->assertJsonPath('data.version', 2);
});

it('keeps a published later version current when the reference data is seeded again', function () {
    $draft = readinessDraft();
    $this->postJson(route('api.v1.readiness-questionnaires.publish', $draft['id']))->assertOk();

    $this->seed(ReferenceDataSeeder::class);
    $this->seed(ReferenceDataSeeder::class);

    expect(ReadinessQuestionnaire::query()->where('is_current', true)->sole()->version)->toBe(2)
        ->and(ReadinessQuestionnaire::query()->count())->toBe(2)
        ->and(ReadinessAssessment::query()->count())->toBe(0);
});

it('is for IMC administrators only', function (Closure $makeUser) {
    Sanctum::actingAs($makeUser());
    $current = ReadinessQuestionnaire::query()->where('is_current', true)->sole();

    $this->getJson(route('api.v1.readiness-questionnaires.index'))->assertForbidden();
    $this->getJson(route('api.v1.readiness-questionnaires.show', $current))->assertForbidden();
    $this->postJson(route('api.v1.readiness-questionnaires.store'))->assertForbidden();
    $this->putJson(route('api.v1.readiness-questionnaires.update', $current), [])->assertForbidden();
    $this->postJson(route('api.v1.readiness-questionnaires.publish', $current))->assertForbidden();
    $this->deleteJson(route('api.v1.readiness-questionnaires.destroy', $current))->assertForbidden();
    expect(ReadinessQuestionnaire::query()->count())->toBe(1);
})->with([
    'factory member' => [fn () => User::factory()->factoryMember()->create()],
    'provider member' => [fn () => User::factory()->providerMember(ServiceProvider::factory()->create())->create()],
]);

it('keeps the source shape: five pillars, two questions each, four choices worth 1 to 4', function (Closure $tamper, string $field) {
    $draft = readinessDraft();
    $definition = questionnaireDefinition($draft);
    $tamper($definition);

    $this->putJson(route('api.v1.readiness-questionnaires.update', $draft['id']), $definition)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
    // Nothing of the draft changed.
    expect(ReadinessQuestion::query()->where('readiness_questionnaire_id', $draft['id'])->count())->toBe(10);
})->with([
    'points above 4' => [function (array &$definition): void {
        $definition['pillars'][0]['questions'][0]['choices'][3]['points'] = 5;
    }, 'pillars.0.questions.0.choices.3.points'],
    'points of 0' => [function (array &$definition): void {
        $definition['pillars'][0]['questions'][0]['choices'][0]['points'] = 0;
    }, 'pillars.0.questions.0.choices.0.points'],
    'a point value used twice' => [function (array &$definition): void {
        $definition['pillars'][0]['questions'][0]['choices'][1]['points'] = 1;
    }, 'pillars.0.questions.0.choices'],
    'two choices with the same label' => [function (array &$definition): void {
        $definition['pillars'][0]['questions'][0]['choices'][1]['label_ar'] = $definition['pillars'][0]['questions'][0]['choices'][0]['label_ar'];
    }, 'pillars.0.questions.0.choices'],
    'a fifth choice' => [function (array &$definition): void {
        $definition['pillars'][0]['questions'][0]['choices'][] = ['code' => 'e', 'label_ar' => 'هـ', 'text_ar' => 'اختيار إضافي', 'points' => 4];
    }, 'pillars.0.questions.0.choices'],
    'a third question in a pillar' => [function (array &$definition): void {
        $definition['pillars'][0]['questions'][] = [...$definition['pillars'][0]['questions'][0], 'code' => 'q11'];
    }, 'pillars.0.questions'],
    'a pillar removed' => [function (array &$definition): void {
        array_pop($definition['pillars']);
    }, 'pillars'],
    'a renamed pillar code' => [function (array &$definition): void {
        $definition['pillars'][0]['code'] = 'innovation';
    }, 'pillars'],
    'a question moved to another pillar' => [function (array &$definition): void {
        [$definition['pillars'][0]['questions'][0], $definition['pillars'][1]['questions'][0]] = [$definition['pillars'][1]['questions'][0], $definition['pillars'][0]['questions'][0]];
    }, 'pillars.0.questions'],
    'a new question code' => [function (array &$definition): void {
        $definition['pillars'][0]['questions'][0]['code'] = 'q99';
    }, 'pillars.0.questions'],
    'a new choice code' => [function (array &$definition): void {
        $definition['pillars'][0]['questions'][0]['choices'][0]['code'] = 'z';
    }, 'pillars.0.questions.0.choices'],
]);

it('edits the roadmap recommendations of a draft, mapped to existing catalog services only', function () {
    $draft = readinessDraft();
    $definition = questionnaireDefinition($draft);
    $catalogCount = CatalogService::query()->count();
    $definition['categories'][0]['recommendations'] = [
        ['text_ar' => 'نظم تخطيط وإدارة موارد المؤسسات (ERP).)', 'services' => ['erp_business_applications.01']],
        ['text_ar' => 'سطر توصية لا يقابله خدمة في الدليل', 'services' => []],
    ];

    $response = $this->putJson(route('api.v1.readiness-questionnaires.update', $draft['id']), $definition)->assertOk();

    expect($response->json('data.definition.categories.0.roadmap.recommendations.*.text_ar'))->toBe(['نظم تخطيط وإدارة موارد المؤسسات (ERP).)', 'سطر توصية لا يقابله خدمة في الدليل'])
        ->and($response->json('data.definition.categories.0.roadmap.recommendations.0.services.*.code'))->toBe(['erp_business_applications.01'])
        ->and($response->json('data.definition.categories.0.roadmap.recommendations.1.services'))->toBe([])
        // The other categories keep the lines copied from version 1.
        ->and(count($response->json('data.definition.categories.1.roadmap.recommendations')))->toBeGreaterThan(0)
        ->and(CatalogService::query()->count())->toBe($catalogCount);
    // Version 1 keeps its 48 lines: only the draft's copies were replaced.
    $versionOneId = ReadinessQuestionnaire::query()->where('version', 1)->value('id');
    expect(ReadinessRecommendation::query()->whereHas('category', fn ($query) => $query->where('readiness_questionnaire_id', $versionOneId))->count())->toBe(48)
        ->and(AuditLog::query()->where('event', AuditEvent::ReadinessQuestionnaireUpdated)->sole()->metadata['recommendations'])->toEqual(['b4_automation' => 2]);
});

it('refuses a roadmap without lines or mapped to a service the catalog lacks', function (Closure $tamper, string $field) {
    $draft = readinessDraft();
    $definition = questionnaireDefinition($draft);
    $tamper($definition);

    $this->putJson(route('api.v1.readiness-questionnaires.update', $draft['id']), $definition)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'no line' => [function (array &$definition): void {
        $definition['categories'][0]['recommendations'] = [];
    }, 'categories.0.recommendations'],
    'an empty line' => [function (array &$definition): void {
        $definition['categories'][0]['recommendations'] = [['text_ar' => '', 'services' => []]];
    }, 'categories.0.recommendations.0.text_ar'],
    'an unknown catalog service' => [function (array &$definition): void {
        $definition['categories'][0]['recommendations'] = [['text_ar' => 'خدمة غير موجودة', 'services' => ['not_in_catalog.99']]];
    }, 'categories.0.recommendations.0.services.0'],
]);

it('records who drafted, changed and published each version', function () {
    $admin = User::factory()->imcAdmin()->create(['name' => 'Draft Author']);
    Sanctum::actingAs($admin);
    $draft = $this->postJson(route('api.v1.readiness-questionnaires.store'))->assertCreated()->json('data');
    expect($draft['created_by'])->toBe(['id' => $admin->id, 'name' => 'Draft Author'])
        ->and($draft['published_by'])->toBeNull();

    $editor = User::factory()->imcAdmin()->create(['name' => 'Draft Editor']);
    Sanctum::actingAs($editor);
    $this->putJson(route('api.v1.readiness-questionnaires.update', $draft['id']), questionnaireDefinition($draft))
        ->assertOk()
        ->assertJsonPath('data.updated_by.name', 'Draft Editor')
        ->assertJsonPath('data.created_by.name', 'Draft Author');
    $this->postJson(route('api.v1.readiness-questionnaires.publish', $draft['id']))
        ->assertOk()
        ->assertJsonPath('data.published_by.name', 'Draft Editor');

    $this->getJson(route('api.v1.readiness-questionnaires.index'))
        ->assertJsonPath('data.0.published_by.id', $editor->id)
        // The seeded version 1 is the source document: no actor.
        ->assertJsonPath('data.1.created_by', null)
        ->assertJsonPath('data.1.published_by', null);
});

it('checks the stored structure again before publishing', function (Closure $break) {
    $draft = readinessDraft();
    $break(ReadinessQuestionnaire::query()->findOrFail($draft['id']));

    $this->postJson(route('api.v1.readiness-questionnaires.publish', $draft['id']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['definition']);
    expect(ReadinessQuestionnaire::query()->where('is_current', true)->sole()->version)->toBe(1);
})->with([
    'a choice worth 7 points' => [function (ReadinessQuestionnaire $draft): void {
        ReadinessChoice::query()->whereIn('readiness_question_id', $draft->questions()->select('id'))->where('code', 'd')->limit(1)->update(['points' => 7]);
    }],
    'a question with three choices' => [function (ReadinessQuestionnaire $draft): void {
        ReadinessChoice::query()->whereIn('readiness_question_id', $draft->questions()->select('id'))->where('code', 'd')->limit(1)->delete();
    }],
    'a category without recommendations' => [function (ReadinessQuestionnaire $draft): void {
        ReadinessRecommendation::query()->whereIn('readiness_category_id', $draft->categories()->select('id'))->delete();
    }],
]);
