<?php

use App\Enums\AgreementReviewStatus;
use App\Enums\PlanItemExecutionStatus;
use App\Models\Agreement;
use App\Models\ProviderRequest;
use App\Models\ServiceProvider;
use App\Models\ServiceRequest;
use App\Models\TransformationPlan;
use App\Models\TransformationPlanItem;
use App\Models\User;
use App\TransformationPlans\PlanItemRequests;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * A published plan in execution (ADR-025): requests linked to plan items through the
 * existing marketplace, and the execution IMC records, gated by prerequisites and by the
 * agreement IMC approved.
 */
beforeEach(function () {
    $this->fixture = planFixture();
    $this->services = $this->fixture['services'];
    $this->plan = publishedPlan($this->fixture);
});

/**
 * The factory sends a request for the plan item through the API.
 *
 * @param  list<int>|null  $providerIds
 */
function requestPlanItem(array $fixture, TransformationPlanItem $item, string $serviceCode, ?array $providerIds = null): TestResponse
{
    Sanctum::actingAs($fixture['member']);

    return test()->postJson(route('api.v1.service-requests.store'), [
        'service' => $serviceCode,
        'title' => 'طلب من خطة التحول',
        'need' => 'تنفيذ الخدمة ضمن خطة التحول الرقمي.',
        'provider_ids' => $providerIds ?? [$fixture['provider']->id],
        'transformation_plan_item_id' => $item->id,
    ]);
}

/**
 * Drive the request to an agreement through the API: the provider accepts and offers, the
 * factory accepts the offer. IMC's decision is stored with imcDecision() when given.
 */
function agreeOnRequest(array $fixture, int $serviceRequestId, ?AgreementReviewStatus $decision = AgreementReviewStatus::Approved): Agreement
{
    $thread = ProviderRequest::query()->where('service_request_id', $serviceRequestId)->sole();
    Sanctum::actingAs($fixture['providerMember']);
    test()->postJson(route('api.v1.provider-requests.accept', $thread))->assertOk();
    $offer = offerVersion($thread, 1, $fixture['providerMember']);
    Sanctum::actingAs($fixture['member']);
    test()->postJson(route('api.v1.provider-requests.offers.accept', [$thread, $offer]))->assertOk();
    $agreement = Agreement::query()->where('provider_request_id', $thread->id)->sole();

    if ($decision !== null) {
        imcDecision($agreement, $decision);
    }

    return $agreement;
}

/**
 * The item as the reader sees it in the published version.
 *
 * @return array<string, mixed>
 */
function presentedItem(TransformationPlan $plan, int $itemId, User $reader): array
{
    Sanctum::actingAs($reader);

    return collect(test()->getJson(route('api.v1.transformation-plans.show', $plan))->assertOk()->json('data.published.stages'))
        ->flatMap(fn (array $stage) => $stage['items'])
        ->firstWhere('item_id', $itemId);
}

it('links a request to the plan item, and a sent request is not a started item', function () {
    $item = planItem($this->plan, $this->services['a']);

    $id = requestPlanItem($this->fixture, $item, $this->services['a'])
        ->assertCreated()
        ->assertJsonPath('data.transformation_plan_item_id', $item->id)
        ->json('data.id');

    $presented = presentedItem($this->plan, $item->id, $this->fixture['member']);
    expect(ServiceRequest::query()->findOrFail($id)->transformation_plan_item_id)->toBe($item->id)
        ->and($presented['execution_status'])->toBe('not_started')
        ->and($presented['state'])->toBe('not_started')
        ->and($presented['request']['id'])->toBe($id)
        ->and($presented['request']['progress'])->toBe('open')
        ->and($presented['can_request'])->toBeFalse()
        ->and($item->fresh()->started_at)->toBeNull();
});

it('refuses a link to another service, to another factory\'s plan or to a made-up item', function () {
    $item = planItem($this->plan, $this->services['a']);

    requestPlanItem($this->fixture, $item, $this->services['b'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.transformation_plan_item_id.0', PlanItemRequests::OTHER_SERVICE);
    requestPlanItem($this->fixture, $item, $this->services['a'], [$this->fixture['provider']->id])->assertCreated();

    $other = planFixture();
    Sanctum::actingAs($other['member']);
    $this->postJson(route('api.v1.service-requests.store'), [
        'service' => $this->services['b'], 'title' => 'x', 'need' => 'y',
        'provider_ids' => [$other['provider']->id],
        'transformation_plan_item_id' => planItem($this->plan, $this->services['b'])->id,
    ])->assertUnprocessable()->assertJsonPath('errors.transformation_plan_item_id.0', PlanItemRequests::NOT_IN_PLAN);
    $this->postJson(route('api.v1.service-requests.store'), [
        'service' => $this->services['b'], 'title' => 'x', 'need' => 'y',
        'provider_ids' => [$other['provider']->id],
        'transformation_plan_item_id' => 999999,
    ])->assertUnprocessable()->assertJsonPath('errors.transformation_plan_item_id.0', PlanItemRequests::NOT_IN_PLAN);

    expect(ServiceRequest::query()->count())->toBe(1);
});

it('sends the request for an item with an assigned provider to that provider only', function () {
    $fixture = planFixture();
    $other = ServiceProvider::factory()->approved()->inSectors('food')->offering($fixture['services']['c'])->create();
    $plan = publishedPlan($fixture, $fixture['provider']->id);
    $item = planItem($plan, $fixture['services']['c']);

    requestPlanItem($fixture, $item, $fixture['services']['c'], [$other->id])
        ->assertUnprocessable()
        ->assertJsonPath('errors.provider_ids.0', PlanItemRequests::ASSIGNED_PROVIDER);
    requestPlanItem($fixture, $item, $fixture['services']['c'], [$fixture['provider']->id, $other->id])->assertUnprocessable();
    $id = requestPlanItem($fixture, $item, $fixture['services']['c'], [$fixture['provider']->id])->assertCreated()->json('data.id');

    $this->postJson(route('api.v1.service-requests.providers.store', $id), ['provider_ids' => [$other->id]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.provider_ids.0', PlanItemRequests::ASSIGNED_PROVIDER);
});

it('allows one live request per item; a cancellation or an IMC rejection frees it', function () {
    $item = planItem($this->plan, $this->services['a']);
    $first = requestPlanItem($this->fixture, $item, $this->services['a'])->assertCreated()->json('data.id');

    requestPlanItem($this->fixture, $item, $this->services['a'])->assertConflict();

    $this->postJson(route('api.v1.service-requests.cancel', $first))->assertOk();
    $second = requestPlanItem($this->fixture, $item, $this->services['a'])->assertCreated()->json('data.id');

    agreeOnRequest($this->fixture, $second, AgreementReviewStatus::Rejected);
    $presented = presentedItem($this->plan, $item->id, $this->fixture['admin']);
    expect($presented['attention'])->toContain('agreement_rejected')
        ->and($presented['request']['progress'])->toBe('imc_rejected')
        ->and($presented['state'])->toBe('not_started');

    requestPlanItem($this->fixture, $item, $this->services['a'])->assertCreated();
    expect(ServiceRequest::query()->where('transformation_plan_item_id', $item->id)->count())->toBe(3);
});

it('starts an item only once its agreement is approved by IMC, never on the request alone', function () {
    $item = planItem($this->plan, $this->services['a']);
    $start = fn () => test()->postJson(route('api.v1.transformation-plans.items.start', [$this->plan, $item]));

    Sanctum::actingAs($this->fixture['admin']);
    $start()->assertConflict();

    $id = requestPlanItem($this->fixture, $item, $this->services['a'])->json('data.id');
    Sanctum::actingAs($this->fixture['admin']);
    $start()->assertConflict();

    $agreement = agreeOnRequest($this->fixture, $id, null);
    expect(presentedItem($this->plan, $item->id, $this->fixture['admin'])['state'])->toBe('awaiting_approval');
    $start()->assertConflict();

    imcDecision($agreement, AgreementReviewStatus::Approved);
    $presented = presentedItem($this->plan, $item->id, $this->fixture['admin']);
    expect($presented['state'])->toBe('ready')
        ->and($presented['allowed_actions'])->toContain('start');

    $start()->assertOk();
    $this->postJson(route('api.v1.transformation-plans.items.complete', [$this->plan, $item]))->assertOk();

    expect($item->fresh()->execution_status)->toBe(PlanItemExecutionStatus::Completed)
        ->and($item->fresh()->started_at)->not->toBeNull()
        ->and($item->fresh()->completed_at)->not->toBeNull();
});

it('keeps a dependent item waiting until its prerequisite is completed, while independent items run in parallel', function () {
    $a = planItem($this->plan, $this->services['a']);
    $b = planItem($this->plan, $this->services['b']);
    $c = planItem($this->plan, $this->services['c']);
    foreach ([[$a, 'a'], [$b, 'b'], [$c, 'c']] as [$item, $key]) {
        agreeOnRequest($this->fixture, requestPlanItem($this->fixture, $item, $this->services[$key])->assertCreated()->json('data.id'));
    }

    // C may be requested and agreed before A completes, but it cannot start.
    expect(presentedItem($this->plan, $c->id, $this->fixture['member'])['state'])->toBe('waiting_prerequisites')
        ->and(array_column(presentedItem($this->plan, $c->id, $this->fixture['member'])['waiting_for'], 'item_id'))->toBe([$a->id]);
    Sanctum::actingAs($this->fixture['admin']);
    $this->postJson(route('api.v1.transformation-plans.items.start', [$this->plan, $c]))->assertConflict();

    // A and B run in parallel.
    $this->postJson(route('api.v1.transformation-plans.items.start', [$this->plan, $a]))->assertOk();
    $this->postJson(route('api.v1.transformation-plans.items.start', [$this->plan, $b]))->assertOk();
    $this->postJson(route('api.v1.transformation-plans.items.complete', [$this->plan, $a]))->assertOk();

    expect(presentedItem($this->plan, $c->id, $this->fixture['member'])['state'])->toBe('ready');
    Sanctum::actingAs($this->fixture['admin']);
    $this->postJson(route('api.v1.transformation-plans.items.start', [$this->plan, $c]))->assertOk();

    $stages = $this->getJson(route('api.v1.transformation-plans.show', $this->plan))->json('data.published.stages');
    expect($stages[0]['status'])->toBe('in_progress')
        ->and($stages[0]['progress']['completed'])->toBe(1)
        ->and($stages[0]['progress']['percent'])->toBe(50)
        ->and($stages[1]['status'])->toBe('in_progress')
        ->and($stages[2]['status'])->toBe('not_started');
});

it('shows the factory the real state when the provider declines, and lets IMC record holds and cancellations', function () {
    $a = planItem($this->plan, $this->services['a']);
    $id = requestPlanItem($this->fixture, $a, $this->services['a'])->json('data.id');
    Sanctum::actingAs($this->fixture['providerMember']);
    $this->postJson(route('api.v1.provider-requests.decline', ProviderRequest::query()->where('service_request_id', $id)->sole()), ['reason' => 'No capacity'])->assertOk();

    $presented = presentedItem($this->plan, $a->id, $this->fixture['member']);
    expect($presented['request']['progress'])->toBe('no_active_provider')
        ->and($presented['execution_status'])->toBe('not_started')
        ->and($presented)->not->toHaveKey('attention');
    expect(presentedItem($this->plan, $a->id, $this->fixture['admin'])['attention'])->toContain('no_active_provider');

    Sanctum::actingAs($this->fixture['admin']);
    $this->postJson(route('api.v1.transformation-plans.items.hold', [$this->plan, $a]), ['reason' => 'بانتظار مزود بديل'])->assertOk();
    $this->postJson(route('api.v1.transformation-plans.items.complete', [$this->plan, $a]))->assertConflict();
    $this->postJson(route('api.v1.transformation-plans.items.resume', [$this->plan, $a]))->assertOk();
    expect($a->fresh()->execution_status)->toBe(PlanItemExecutionStatus::NotStarted);
    $this->postJson(route('api.v1.transformation-plans.items.cancel', [$this->plan, $a]))->assertOk();
    $this->postJson(route('api.v1.transformation-plans.items.reopen', [$this->plan, $a]))->assertOk();

    expect(presentedItem($this->plan, $a->id, $this->fixture['member'])['execution_status'])->toBe('not_started');
});

it('pauses requests and starts while the plan is suspended', function () {
    $a = planItem($this->plan, $this->services['a']);
    Sanctum::actingAs($this->fixture['admin']);
    $this->postJson(route('api.v1.transformation-plans.suspend', $this->plan))->assertOk();

    requestPlanItem($this->fixture, $a, $this->services['a'])->assertConflict();
    expect(presentedItem($this->plan, $a->id, $this->fixture['member'])['can_request'])->toBeFalse();
});

it('refuses an item of another plan through this plan\'s route', function () {
    $other = planFixture();
    $otherPlan = publishedPlan($other);
    Sanctum::actingAs($this->fixture['admin']);

    $this->postJson(route('api.v1.transformation-plans.items.hold', [$this->plan, planItem($otherPlan, $other['services']['a'])]))->assertNotFound();
});

it('locks the factory, the plan, the item, the level row and the providers in that order', function () {
    $item = planItem($this->plan, $this->services['a']);

    $reads = lockingReads(fn () => requestPlanItem($this->fixture, $item, $this->services['a'])->assertCreated());

    expect($reads)->toBe([
        'factories:share',
        'transformation_plans:share',
        'transformation_plan_items:update',
        'readiness_level_services:share',
        'service_providers:share',
    ]);
});
