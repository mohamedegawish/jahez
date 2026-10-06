<?php

use App\Enums\TransformationPlanStatus;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\ReadinessLevelService;
use App\Models\ServiceProvider;
use App\Models\TransformationPlan;
use App\Models\TransformationPlanVersion;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Building a factory's transformation plan (ADR-025): stages, services, order,
 * dependencies, drafts, publication and versions, and who may do what.
 */
beforeEach(function () {
    $this->fixture = planFixture();
    $this->services = $this->fixture['services'];
});

/**
 * Create the factory's plan through the API as IMC and return its id.
 */
function createPlan(array $fixture): int
{
    Sanctum::actingAs($fixture['admin']);

    return test()->postJson(route('api.v1.factories.transformation-plans.store', $fixture['factory']), ['title' => 'خطة'])->assertCreated()->json('data.id');
}

it('builds a multi-stage plan with parallel and sequential services, kept in order after a reload', function () {
    $planId = createPlan($this->fixture);

    $this->putJson(route('api.v1.transformation-plans.draft.update', $planId), planDraftPayload($this->services, 0, $this->fixture['provider']->id))
        ->assertOk()
        ->assertJsonPath('data.draft.revision', 1);

    $draft = $this->getJson(route('api.v1.transformation-plans.show', $planId))->assertOk()->json('data.draft');
    $stages = collect($draft['stages']);
    $items = $stages->flatMap(fn (array $stage) => $stage['items'])->keyBy('service.code');
    $id = fn (string $key): int => $items[$this->services[$key]]['item_id'];

    expect($stages->pluck('name_ar')->all())->toBe(['التأسيس والتجهيز', 'التطوير والتكامل', 'التحسين والتوسع'])
        ->and($stages->pluck('number')->all())->toBe([1, 2, 3])
        ->and(array_column($stages[0]['items'], 'position'))->toBe([1, 2])
        ->and(array_column(array_column($stages[0]['items'], 'service'), 'code'))->toBe([$this->services['a'], $this->services['b']])
        ->and($stages[0]['planned_start_date'])->toBe('2026-11-01')
        // A and B run in parallel; C waits for A; D runs alongside C; E waits for C and D.
        ->and($items[$this->services['a']]['parallel_with'])->toBe([$id('b')])
        ->and(array_column($items[$this->services['c']]['depends_on'], 'item_id'))->toBe([$id('a')])
        ->and($items[$this->services['c']]['parallel_with'])->toBe([$id('d')])
        ->and($items[$this->services['c']]['state'])->toBe('waiting_prerequisites')
        ->and($items[$this->services['a']]['state'])->toBe('not_started')
        ->and(array_column($items[$this->services['e']]['depends_on'], 'item_id'))->toBe([$id('c'), $id('d')])
        ->and($items[$this->services['a']]['dependents'])->toBe([$id('c')])
        ->and($items[$this->services['c']]['provider']['id'])->toBe($this->fixture['provider']->id)
        ->and($draft['progress'])->toBe(['total' => 5, 'completed' => 0, 'in_progress' => 0, 'on_hold' => 0, 'cancelled' => 0, 'percent' => 0]);
});

it('refuses dependencies that form a cycle, point outside the plan or to the service itself', function (Closure $mutate, string $field) {
    $planId = createPlan($this->fixture);
    $payload = planDraftPayload($this->services);
    $mutate($payload, $this->services);

    $this->putJson(route('api.v1.transformation-plans.draft.update', $planId), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(TransformationPlanVersion::query()->where('transformation_plan_id', $planId)->sole()->revision)->toBe(0);
})->with([
    'a cycle' => [function (array &$payload, array $services): void {
        $payload['stages'][0]['items'][0]['depends_on'] = [$services['e']];
    }, 'stages'],
    'a service not in the plan' => [function (array &$payload): void {
        $payload['stages'][0]['items'][1]['depends_on'] = ['dx_consulting_enablement.01'];
    }, 'stages.0.items.1.depends_on.0'],
    'itself' => [function (array &$payload, array $services): void {
        $payload['stages'][1]['items'][1]['depends_on'] = [$services['d']];
    }, 'stages.1.items.1.depends_on.0'],
    'a duplicated service' => [function (array &$payload, array $services): void {
        $payload['stages'][2]['items'][] = ['service' => $services['a']];
    }, 'stages.2.items.1.service'],
    'an unknown service' => [function (array &$payload): void {
        $payload['stages'][0]['items'][] = ['service' => 'not_a_service.01'];
    }, 'stages.0.items.2.service'],
    'an end date before its start' => [function (array &$payload): void {
        $payload['stages'][0]['planned_end_date'] = '2026-10-01';
    }, 'stages.0.planned_end_date'],
    'a malformed date' => [function (array &$payload): void {
        $payload['stages'][0]['items'][0]['planned_start_date'] = '01/11/2026';
    }, 'stages.0.items.0.planned_start_date'],
    'an unexpected key' => [function (array &$payload): void {
        $payload['stages'][0]['items'][0]['execution_status'] = 'completed';
    }, 'stages.0.items.0'],
]);

it('refuses a save based on a stale revision, so two editors never overwrite each other', function () {
    $planId = createPlan($this->fixture);
    $this->putJson(route('api.v1.transformation-plans.draft.update', $planId), planDraftPayload($this->services, 0))->assertOk();

    $this->putJson(route('api.v1.transformation-plans.draft.update', $planId), planDraftPayload($this->services, 0))->assertConflict();

    expect(TransformationPlanVersion::query()->where('transformation_plan_id', $planId)->sole()->revision)->toBe(1);
});

it('publishes the plan to the factory, which never sees IMC\'s notes or a draft', function () {
    $planId = createPlan($this->fixture);
    $this->putJson(route('api.v1.transformation-plans.draft.update', $planId), planDraftPayload($this->services))->assertOk();

    Sanctum::actingAs($this->fixture['member']);
    $this->getJson(route('api.v1.transformation-plans.show', $planId))->assertNotFound();
    expect($this->getJson(route('api.v1.transformation-plans.index'))->assertOk()->json('data'))->toBe([]);

    Sanctum::actingAs($this->fixture['admin']);
    $this->postJson(route('api.v1.transformation-plans.publish', $planId))
        ->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.published_version.version', 1)
        ->assertJsonPath('data.draft', null);

    Sanctum::actingAs($this->fixture['member']);
    $response = $this->getJson(route('api.v1.transformation-plans.show', $planId))->assertOk();
    $json = json_encode($response->json());

    expect($response->json('data.published.stages'))->toHaveCount(3)
        ->and($response->json('data.published.stages.0.factory_instructions_ar'))->toBe('جهّزوا بيانات الإنتاج.')
        ->and($json)->not->toContain('ملاحظة داخلية')
        ->and($response->json('data'))->not->toHaveKeys(['draft', 'review', 'status_reason', 'readiness_basis'])
        ->and($response->json('data.published.stages.0.items.0'))->not->toHaveKeys(['internal_notes', 'allowed_actions'])
        ->and($this->getJson(route('api.v1.transformation-plans.index'))->json('data.*.id'))->toBe([$planId]);
    $this->getJson(route('api.v1.transformation-plans.versions.index', $planId))->assertForbidden();
});

it('refuses to publish while a blocking problem remains', function (Closure $mutate, string $code) {
    $planId = createPlan($this->fixture);
    $payload = planDraftPayload($this->services, 0, $this->fixture['provider']->id);
    $mutate($payload, $this->fixture);
    $this->putJson(route('api.v1.transformation-plans.draft.update', $planId), $payload)->assertOk();

    $review = collect($this->getJson(route('api.v1.transformation-plans.show', $planId))->json('data.review'));
    expect($review->where('blocking', true)->pluck('code')->all())->toContain($code);

    $this->postJson(route('api.v1.transformation-plans.publish', $planId))->assertUnprocessable()->assertJsonValidationErrors(['draft']);
    expect(TransformationPlan::query()->findOrFail($planId)->status)->toBe(TransformationPlanStatus::Draft);
})->with([
    'no stage' => [function (array &$payload): void {
        $payload['stages'] = [];
    }, 'no_stages'],
    'an empty stage' => [function (array &$payload): void {
        $payload['stages'][] = ['name_ar' => 'مرحلة فارغة', 'items' => []];
    }, 'empty_stage'],
    'a service the factory\'s level does not offer' => [function (array &$payload): void {
        $payload['stages'][2]['items'][] = ['service' => 'dx_consulting_enablement.01'];
    }, 'service_not_available'],
    'an ineligible assigned provider' => [function (array &$payload, array $fixture): void {
        $payload['stages'][1]['items'][0]['service_provider_id'] = ServiceProvider::factory()->approved()->inSectors('chemical')->offering($fixture['services']['c'])->create()->id;
    }, 'provider_not_eligible'],
]);

it('keeps the previous version when a new one is published, and asks why it changed', function () {
    $plan = publishedPlan($this->fixture);
    Sanctum::actingAs($this->fixture['admin']);

    $this->postJson(route('api.v1.transformation-plans.draft.store', $plan))->assertCreated()->assertJsonPath('data.draft.version', 2);
    $this->postJson(route('api.v1.transformation-plans.draft.store', $plan))->assertConflict();

    $payload = planDraftPayload($this->services, 0);
    array_pop($payload['stages']);
    $payload['stages'][1]['items'][1]['depends_on'] = [$this->services['b']];
    $this->putJson(route('api.v1.transformation-plans.draft.update', $plan), $payload)->assertOk();

    $this->postJson(route('api.v1.transformation-plans.publish', $plan))->assertUnprocessable()->assertJsonValidationErrors(['change_note']);
    $this->postJson(route('api.v1.transformation-plans.publish', $plan), ['change_note' => 'دمج المرحلة الثالثة لاحقًا.'])
        ->assertOk()
        ->assertJsonPath('data.published_version.version', 2);

    $versions = collect($this->getJson(route('api.v1.transformation-plans.versions.index', $plan))->assertOk()->json('data'))->keyBy('version');
    expect($versions[1]['status'])->toBe('superseded')
        ->and($versions[1]['stage_count'])->toBe(3)
        ->and($versions[2]['status'])->toBe('published')
        ->and($versions[2]['stage_count'])->toBe(2)
        ->and($versions[2]['change_note'])->toBe('دمج المرحلة الثالثة لاحقًا.');

    $this->getJson(route('api.v1.transformation-plans.versions.show', [$plan, $versions[1]['id']]))
        ->assertOk()
        ->assertJsonCount(3, 'data.stages');
});

it('discards a draft of a published plan but deletes only a plan never published', function () {
    $planId = createPlan($this->fixture);
    $this->deleteJson(route('api.v1.transformation-plans.draft.destroy', $planId))->assertConflict();
    $this->deleteJson(route('api.v1.transformation-plans.destroy', $planId))->assertNoContent();
    expect(TransformationPlan::query()->whereKey($planId)->exists())->toBeFalse();

    $plan = publishedPlan($this->fixture);
    Sanctum::actingAs($this->fixture['admin']);
    $this->postJson(route('api.v1.transformation-plans.draft.store', $plan))->assertCreated();
    $this->deleteJson(route('api.v1.transformation-plans.draft.destroy', $plan))->assertOk()->assertJsonPath('data.draft', null);
    $this->deleteJson(route('api.v1.transformation-plans.destroy', $plan))->assertConflict();
});

it('suspends, resumes and closes a plan; a closed plan lets the factory receive a new one', function () {
    $plan = publishedPlan($this->fixture);
    Sanctum::actingAs($this->fixture['admin']);

    $this->postJson(route('api.v1.factories.transformation-plans.store', $this->fixture['factory']), ['title' => 'ثانية'])->assertConflict();
    $this->postJson(route('api.v1.transformation-plans.resume', $plan))->assertConflict();
    $this->postJson(route('api.v1.transformation-plans.suspend', $plan), ['reason' => 'مراجعة الميزانية'])->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->postJson(route('api.v1.transformation-plans.resume', $plan))->assertOk()->assertJsonPath('data.status', 'published');
    $this->postJson(route('api.v1.transformation-plans.close', $plan))->assertOk()->assertJsonPath('data.status', 'closed');
    $this->postJson(route('api.v1.transformation-plans.close', $plan))->assertConflict();
    $this->postJson(route('api.v1.transformation-plans.draft.store', $plan))->assertConflict();

    $this->postJson(route('api.v1.factories.transformation-plans.store', $this->fixture['factory']), ['title' => 'ثانية'])->assertCreated();
});

it('needs a readiness assessment before a plan can be built for the factory', function () {
    $factory = Factory::factory()->inSectors('food')->create();
    Sanctum::actingAs($this->fixture['admin']);

    $this->postJson(route('api.v1.factories.transformation-plans.store', $factory), ['title' => 'خطة'])->assertConflict();
    expect(TransformationPlan::query()->count())->toBe(0);
});

it('warns IMC when the factory\'s level changed after publication, and keeps the plan', function () {
    $plan = publishedPlan($this->fixture);
    ReadinessLevelService::query()->delete();
    Sanctum::actingAs($this->fixture['member']);
    $this->postJson(route('api.v1.factories.readiness-assessments.store', $this->fixture['factory']), readinessPayload(readinessChoicesForTotal(36)))->assertCreated();

    $item = $this->getJson(route('api.v1.transformation-plans.show', $plan))->assertOk()->json('data.published.stages.0.items.0');
    expect($item['service_available'])->toBeFalse()->and($item['can_request'])->toBeFalse();

    Sanctum::actingAs($this->fixture['admin']);
    $this->postJson(route('api.v1.transformation-plans.draft.store', $plan))->assertCreated();
    $response = $this->getJson(route('api.v1.transformation-plans.show', $plan))->assertOk();

    expect($response->json('data.readiness_changed'))->toBeTrue()
        ->and($response->json('data.status'))->toBe('published')
        ->and(collect($response->json('data.review'))->pluck('code')->all())->toContain('readiness_changed', 'service_not_available');
});

it('lets only IMC administrators write a plan; factories and providers cannot', function () {
    $plan = publishedPlan($this->fixture);
    $otherFactoryMember = User::factory()->factoryMember(Factory::factory()->create())->create();

    $cases = [
        [$this->fixture['member'], 403, 403],
        [$this->fixture['providerMember'], 404, 404],
        [$otherFactoryMember, 404, 404],
    ];

    foreach ($cases as [$user, $planStatus, $createStatus]) {
        Sanctum::actingAs($user);
        $this->postJson(route('api.v1.transformation-plans.draft.store', $plan))->assertStatus($planStatus);
        $this->putJson(route('api.v1.transformation-plans.draft.update', $plan), planDraftPayload($this->services))->assertStatus($planStatus);
        $this->postJson(route('api.v1.transformation-plans.suspend', $plan))->assertStatus($planStatus);
        $this->postJson(route('api.v1.transformation-plans.items.start', [$plan, planItem($plan, $this->services['a'])]))->assertStatus($planStatus);
        $this->postJson(route('api.v1.factories.transformation-plans.store', $this->fixture['factory']), ['title' => 'x'])->assertStatus($createStatus);
    }

    Sanctum::actingAs($this->fixture['providerMember']);
    $this->getJson(route('api.v1.transformation-plans.show', $plan))->assertNotFound();
    $this->getJson(route('api.v1.transformation-plans.index'))->assertForbidden();
    Sanctum::actingAs($otherFactoryMember);
    $this->getJson(route('api.v1.transformation-plans.show', $plan))->assertNotFound();
    expect($this->getJson(route('api.v1.transformation-plans.index'))->json('data'))->toBe([]);

    expect(TransformationPlan::query()->count())->toBe(1)
        ->and(TransformationPlanVersion::query()->count())->toBe(1);
});

it('lists plans for IMC with their factory, status and progress', function () {
    $plan = publishedPlan($this->fixture);
    Sanctum::actingAs($this->fixture['admin']);

    $this->getJson(route('api.v1.transformation-plans.index', ['filter' => ['status' => 'published']]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $plan->id)
        ->assertJsonPath('data.0.factory.id', $this->fixture['factory']->id)
        ->assertJsonPath('data.0.progress.total', 5)
        ->assertJsonPath('data.0.progress.percent', 0);
    expect($this->getJson(route('api.v1.transformation-plans.index', ['filter' => ['status' => 'closed']]))->json('data'))->toBe([]);
    $this->getJson(route('api.v1.transformation-plans.index', ['filter' => ['status' => 'bogus']]))->assertUnprocessable();
});

it('never changes the catalog when a plan uses or drops a service', function () {
    $count = CatalogService::query()->count();
    publishedPlan($this->fixture);

    expect(CatalogService::query()->count())->toBe($count);
});
