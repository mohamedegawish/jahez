<?php

use App\Enums\AuditEvent;
use App\Enums\NotificationEvent;
use App\Enums\PlanItemExecutionStatus;
use App\Enums\ReadinessCategoryCode;
use App\Http\Requests\Api\V1\StoreServiceRequestRequest;
use App\Models\AuditLog;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\ReadinessLevelService;
use App\Models\ReadinessLevelUnlock;
use App\Models\ServiceProvider;
use App\Models\TransformationPlan;
use App\Models\User;
use App\Readiness\ServiceEligibility;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Cumulative readiness levels and plan-based progression (ADR-026, owner decisions
 * 2026-10-06): a factory may use the services of its level and of every level below it,
 * and completing every service of its level in its published plan opens the next level,
 * for good.
 *
 * planFixture(): the factory is assessed Basic (20 points) and the five plan services are
 * Basic services. Here one more service is made available to each other level.
 */
const PROGRESSION_LOWER = 'dx_consulting_enablement.01';
const PROGRESSION_NEXT = 'ai_data_analytics.02';
const PROGRESSION_TOP = 'cloud_infrastructure.02';

beforeEach(function () {
    $this->fixture = planFixture();
    $this->services = $this->fixture['services'];
    progressionLevelService(ReadinessCategoryCode::B4Automation, PROGRESSION_LOWER);
    progressionLevelService(ReadinessCategoryCode::Advanced, PROGRESSION_NEXT);
    progressionLevelService(ReadinessCategoryCode::Smart, PROGRESSION_TOP);
    $this->otherProvider = ServiceProvider::factory()->approved()->inSectors('food')->offering(PROGRESSION_LOWER, PROGRESSION_NEXT, PROGRESSION_TOP)->create();
});

function progressionLevelService(ReadinessCategoryCode $level, string $code): void
{
    $row = new ReadinessLevelService;
    $row->level = $level;
    $row->catalog_service_id = CatalogService::query()->where('code', $code)->value('id');
    $row->save();
}

/**
 * Record items as completed directly, as IMC would have after starting them, without
 * going through the action that checks progression.
 */
function completedDirectly(TransformationPlan $plan, User $admin, string ...$codes): void
{
    foreach ($codes as $code) {
        $item = planItem($plan, $code);
        $item->moveTo(PlanItemExecutionStatus::InProgress, $admin);
        $item->moveTo(PlanItemExecutionStatus::Completed, $admin);
    }
}

/**
 * IMC records the last step of an item through the API: an item in progress is
 * completed, one not started is cancelled.
 */
function closeThroughApi(TransformationPlan $plan, User $admin, string $code, string $action = 'complete'): void
{
    $item = planItem($plan, $code);

    if ($action === 'complete') {
        $item->moveTo(PlanItemExecutionStatus::InProgress, $admin);
    }

    Sanctum::actingAs($admin);
    test()->postJson(route("api.v1.transformation-plans.items.{$action}", [$plan, $item]))->assertOk();
    forgetResolvedUsers();
}

/**
 * The factory member asks for the service through the API, as a new request would (a
 * fresh user: the factory's level is read once per request).
 */
function requestAsFactory(array $fixture, string $code, int $providerId): TestResponse
{
    Sanctum::actingAs($fixture['member']->fresh());

    return test()->postJson(route('api.v1.service-requests.store'), [
        'service' => $code,
        'title' => 'طلب خدمة',
        'need' => 'تنفيذ الخدمة.',
        'provider_ids' => [$providerId],
    ]);
}

/**
 * The catalog codes the factory member sees, read as a new request would (a fresh user).
 *
 * @return list<string>
 */
function catalogCodesForFactory(array $fixture): array
{
    Sanctum::actingAs($fixture['member']->fresh());

    return test()->getJson(route('api.v1.catalog.services.index', ['per_page' => 100]))->assertOk()->json('data.*.code');
}

it('gives a factory the services of its level and every level below it, never above, on every path', function () {
    $member = $this->fixture['member'];
    Sanctum::actingAs($member);
    $lower = CatalogService::query()->where('code', PROGRESSION_LOWER)->sole();
    $next = CatalogService::query()->where('code', PROGRESSION_NEXT)->sole();

    expect(catalogCodesForFactory($this->fixture))->toEqualCanonicalizing([PROGRESSION_LOWER, ...array_values($this->services)])
        ->and($this->getJson(route('api.v1.service-listings.index', ['per_page' => 100]))->assertOk()->json('data.*.service.code'))->toContain(PROGRESSION_LOWER)->not->toContain(PROGRESSION_NEXT)
        ->and($this->getJson(route('api.v1.provider-directory', ['filter' => ['service' => PROGRESSION_LOWER]]))->assertOk()->json('data.*.id'))->toBe([$this->otherProvider->id])
        ->and($this->getJson(route('api.v1.provider-directory', ['filter' => ['service' => PROGRESSION_NEXT]]))->assertOk()->json('data'))->toBe([])
        ->and($this->getJson(route('api.v1.provider-directory.show', $this->otherProvider))->assertOk()->json('data.services.*.code'))->toBe([PROGRESSION_LOWER])
        ->and($this->getJson(route('api.v1.factories.service-eligibility.show', $member->factory_id))->json('data.services.*.service.code'))->toContain(PROGRESSION_LOWER)->not->toContain(PROGRESSION_NEXT);

    $this->getJson(route('api.v1.catalog.services.show', $lower))->assertOk();
    $this->getJson(route('api.v1.catalog.services.show', $next))->assertNotFound();
    $this->getJson(route('api.v1.factories.service-eligibility.providers', [$member->factory_id, $lower]))->assertOk()->assertJsonPath('data.0.id', $this->otherProvider->id);
    requestAsFactory($this->fixture, PROGRESSION_LOWER, $this->otherProvider->id)->assertCreated();
    requestAsFactory($this->fixture, PROGRESSION_NEXT, $this->otherProvider->id)
        ->assertUnprocessable()
        ->assertJsonPath('errors.service.0', StoreServiceRequestRequest::SERVICE_NOT_AVAILABLE);
});

it('opens the next level once IMC completes every service of the factory\'s level in its plan', function () {
    $plan = publishedPlan($this->fixture);
    $admin = $this->fixture['admin'];
    $factory = $this->fixture['factory'];
    $otherFactory = Factory::factory()->inSectors('food')->create();
    storedReadinessAssessment($otherFactory, readinessChoicesForTotal(20));

    completedDirectly($plan, $admin, $this->services['a'], $this->services['b'], $this->services['c']);
    closeThroughApi($plan, $admin, $this->services['d']);

    expect(ReadinessLevelUnlock::query()->count())->toBe(0)
        ->and(catalogCodesForFactory($this->fixture))->not->toContain(PROGRESSION_NEXT);

    $this->travelTo('2026-10-06 12:00:00');
    closeThroughApi($plan, $admin, $this->services['e']);

    $unlock = ReadinessLevelUnlock::query()->sole();
    expect($unlock->factory_id)->toBe($factory->id)
        ->and($unlock->level)->toBe(ReadinessCategoryCode::Advanced)
        ->and($unlock->from_level)->toBe(ReadinessCategoryCode::Basic)
        ->and($unlock->transformation_plan_id)->toBe($plan->id)
        ->and($unlock->unlocked_by_user_id)->toBe($admin->id);

    $audit = AuditLog::query()->where('event', AuditEvent::ReadinessLevelUnlocked->value)->sole();
    expect($audit->subject_type)->toBe('factory')
        ->and($audit->subject_id)->toBe($factory->id)
        ->and($audit->actor_user_id)->toBe($admin->id)
        ->and($audit->metadata)->toEqual(['from' => 'basic', 'to' => 'advanced', 'transformation_plan_id' => $plan->id, 'completed_items' => 5])
        ->and($this->fixture['member']->notifications()->where('data->event', NotificationEvent::ReadinessLevelUnlocked->value)->count())->toBe(1);

    // The factory now works at Advanced, keeps every lower level, and still not Smart.
    expect(catalogCodesForFactory($this->fixture))->toEqualCanonicalizing([PROGRESSION_LOWER, PROGRESSION_NEXT, ...array_values($this->services)]);
    $this->getJson(route('api.v1.factories.show', $factory))
        ->assertJsonPath('data.readiness_level.code', 'advanced')
        ->assertJsonPath('data.readiness_level.name_en', 'Advanced')
        ->assertJsonPath('data.readiness_level.unlocked_by', 'plan_completion')
        ->assertJsonPath('data.readiness_level.unlocked_at', '2026-10-06T12:00:00Z')
        ->assertJsonPath('data.current_readiness.category.code', 'basic');
    $this->getJson(route('api.v1.factories.service-eligibility.show', $factory))
        ->assertJsonPath('data.readiness.level', 'advanced')
        ->assertJsonPath('data.readiness.assessed_level', 'basic')
        ->assertJsonPath('data.readiness.unlocked_by', 'plan_completion');
    requestAsFactory($this->fixture, PROGRESSION_NEXT, $this->otherProvider->id)->assertCreated();
    requestAsFactory($this->fixture, PROGRESSION_TOP, $this->otherProvider->id)->assertUnprocessable();

    Sanctum::actingAs($admin);
    $this->getJson(route('api.v1.transformation-plans.show', $plan))
        ->assertJsonPath('data.current_level.code', 'advanced')
        ->assertJsonPath('data.current_level.unlocked_by', 'plan_completion');
    expect(app(ServiceEligibility::class)->levelOf($otherFactory))->toBe(ReadinessCategoryCode::Basic);
});

it('lets a cancelled service no longer hold the level back', function () {
    $plan = publishedPlan($this->fixture);
    $admin = $this->fixture['admin'];

    completedDirectly($plan, $admin, $this->services['a'], $this->services['b'], $this->services['c'], $this->services['d']);
    closeThroughApi($plan, $admin, $this->services['e'], 'cancel');

    expect(ReadinessLevelUnlock::query()->sole()->level)->toBe(ReadinessCategoryCode::Advanced);
});

it('opens nothing for a plan without a service of the factory\'s own level', function () {
    // Every plan service is also a B4 Automation service, so it belongs to that lower level.
    foreach ($this->services as $code) {
        progressionLevelService(ReadinessCategoryCode::B4Automation, $code);
    }
    $plan = publishedPlan($this->fixture);
    $admin = $this->fixture['admin'];

    completedDirectly($plan, $admin, $this->services['a'], $this->services['b'], $this->services['c'], $this->services['d']);
    closeThroughApi($plan, $admin, $this->services['e']);

    expect(ReadinessLevelUnlock::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', AuditEvent::ReadinessLevelUnlocked->value)->count())->toBe(0)
        ->and(catalogCodesForFactory($this->fixture))->not->toContain(PROGRESSION_NEXT);
});

it('opens the level when IMC publishes a version without the last open service of the level', function () {
    $plan = publishedPlan($this->fixture);
    $admin = $this->fixture['admin'];
    completedDirectly($plan, $admin, $this->services['a'], $this->services['b'], $this->services['c'], $this->services['d']);
    Sanctum::actingAs($admin);

    $this->postJson(route('api.v1.transformation-plans.draft.store', $plan))->assertCreated();
    $payload = planDraftPayload($this->services, 0);
    array_pop($payload['stages']);
    $this->putJson(route('api.v1.transformation-plans.draft.update', $plan), $payload)->assertOk();
    expect(ReadinessLevelUnlock::query()->count())->toBe(0);

    $this->postJson(route('api.v1.transformation-plans.publish', $plan), ['change_note' => 'خدمة التحليلات غير مطلوبة لهذه المنشأة.'])->assertOk();

    expect(ReadinessLevelUnlock::query()->sole()->level)->toBe(ReadinessCategoryCode::Advanced);
});

it('keeps an opened level open; only a higher assessment raises the level further', function () {
    $plan = publishedPlan($this->fixture);
    $admin = $this->fixture['admin'];
    $factory = $this->fixture['factory'];
    completedDirectly($plan, $admin, $this->services['a'], $this->services['b'], $this->services['c'], $this->services['d']);
    closeThroughApi($plan, $admin, $this->services['e']);

    // IMC makes one more service available to Basic and closes the plan.
    progressionLevelService(ReadinessCategoryCode::Basic, 'automation_ot.02');
    Sanctum::actingAs($admin);
    $this->postJson(route('api.v1.transformation-plans.close', $plan))->assertOk();

    // A lower self-assessment does not take the opened level away.
    Sanctum::actingAs($this->fixture['member']->fresh());
    $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('a'))->assertCreated();
    $this->getJson(route('api.v1.factories.show', $factory))
        ->assertJsonPath('data.current_readiness.category.code', 'b4_automation')
        ->assertJsonPath('data.readiness_level.code', 'advanced')
        ->assertJsonPath('data.readiness_level.unlocked_by', 'plan_completion');
    expect(catalogCodesForFactory($this->fixture))->toContain(PROGRESSION_NEXT, PROGRESSION_LOWER)->not->toContain(PROGRESSION_TOP);

    // A higher one wins.
    Sanctum::actingAs($this->fixture['member']->fresh());
    $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('d'))->assertCreated();
    $this->getJson(route('api.v1.factories.show', $factory))
        ->assertJsonPath('data.readiness_level.code', 'smart')
        ->assertJsonPath('data.readiness_level.unlocked_by', 'assessment')
        ->assertJsonPath('data.readiness_level.unlocked_at', null);
    expect(catalogCodesForFactory($this->fixture))->toContain(PROGRESSION_TOP)
        ->and(ReadinessLevelUnlock::query()->count())->toBe(1);
});

it('opens no level above Smart', function () {
    ReadinessLevelService::query()
        ->whereIn('catalog_service_id', CatalogService::query()->whereIn('code', array_values($this->services))->select('id'))
        ->update(['level' => ReadinessCategoryCode::Smart->value]);
    Sanctum::actingAs($this->fixture['member']->fresh());
    $this->postJson(route('api.v1.factories.readiness-assessments.store', $this->fixture['factory']), readinessPayload('d'))->assertCreated();
    $plan = publishedPlan($this->fixture);
    $admin = $this->fixture['admin'];

    completedDirectly($plan, $admin, $this->services['a'], $this->services['b'], $this->services['c'], $this->services['d']);
    closeThroughApi($plan, $admin, $this->services['e']);

    expect(ReadinessLevelUnlock::query()->count())->toBe(0)
        ->and(app(ServiceEligibility::class)->levelOf($this->fixture['factory']->fresh()))->toBe(ReadinessCategoryCode::Smart);
});

it('never updates or deletes an opened level', function () {
    $plan = publishedPlan($this->fixture);
    $admin = $this->fixture['admin'];
    completedDirectly($plan, $admin, $this->services['a'], $this->services['b'], $this->services['c'], $this->services['d']);
    closeThroughApi($plan, $admin, $this->services['e']);
    $unlock = ReadinessLevelUnlock::query()->sole();

    $unlock->level = ReadinessCategoryCode::Smart;
    expect(fn () => $unlock->save())->toThrow(LogicException::class)
        ->and(fn () => $unlock->delete())->toThrow(LogicException::class)
        ->and(ReadinessLevelUnlock::query()->sole()->level)->toBe(ReadinessCategoryCode::Advanced);
});
