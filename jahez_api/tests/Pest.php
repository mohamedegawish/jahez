<?php

use App\Billing\PolicyCalendar;
use App\Billing\PolicyParameters;
use App\Enums\AgreementReviewStatus;
use App\Enums\FinancialPolicyKind;
use App\Enums\FinancialPolicyScope;
use App\Enums\FinancialPolicyVersionStatus;
use App\Enums\Permission;
use App\Enums\ProviderRequestStatus;
use App\Enums\ReadinessCategoryCode;
use App\Models\Agreement;
use App\Models\AgreementReview;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\FinancialPolicy;
use App\Models\FinancialPolicyVersion;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\ProviderRequest;
use App\Models\ReadinessAssessment;
use App\Models\ReadinessAssessmentAnswer;
use App\Models\ReadinessChoice;
use App\Models\ReadinessLevelService;
use App\Models\ReadinessQuestionnaire;
use App\Models\ServiceProvider;
use App\Models\ServiceRequest;
use App\Models\TransformationPlan;
use App\Models\TransformationPlanItem;
use App\Models\User;
use App\Models\UserPermissionGrant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->beforeEach(function (): void {
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Issue a real Sanctum bearer token, so requests go through the full guard
 * (expiry, revocation and the active-account check). Use Sanctum::actingAs()
 * instead when a test is only about authorization.
 */
function bearerTokenFor(User $user): string
{
    return $user->createToken('test-device', ['*'], now()->addMinutes(Config::integer('sanctum.expiration')))->plainTextToken;
}

/**
 * Forget resolved guards between requests in one test. Laravel keeps the user a guard
 * resolved for the rest of the test, which would hide a revoked or expired token.
 */
function forgetResolvedUsers(): void
{
    app('auth')->forgetGuards();
}

/**
 * A marketplace scenario for the Phase 6 tests: a food-sector factory whose member sent
 * one service request (ERP, erp_business_applications.01) to $providerCount approved,
 * eligible providers, each thread in $status. The service is available to the factory's
 * readiness level (availableTo()). Seeds the reference data.
 *
 * @return array{factoryMember: User, serviceRequest: ServiceRequest, threads: list<ProviderRequest>, providerMembers: list<User>}
 */
function marketplaceRequest(int $providerCount = 2, ProviderRequestStatus $status = ProviderRequestStatus::Pending): array
{
    test()->seed(ReferenceDataSeeder::class);
    $factory = Factory::factory()->inSectors('food')->create();
    $factoryMember = User::factory()->factoryMember($factory)->create();
    availableTo($factory, 'erp_business_applications.01');
    $serviceRequest = ServiceRequest::factory()->forService('erp_business_applications.01')->create([
        'factory_id' => $factory->id,
        'created_by_user_id' => $factoryMember->id,
    ]);

    $threads = [];
    $providerMembers = [];
    foreach (range(1, $providerCount) as $position) {
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create(['name' => "Provider {$position}"]);
        $providerMembers[] = User::factory()->providerMember($provider)->create();
        $threads[] = ProviderRequest::factory()->withStatus($status)->create([
            'service_request_id' => $serviceRequest->id,
            'service_provider_id' => $provider->id,
        ]);
    }

    return ['factoryMember' => $factoryMember, 'serviceRequest' => $serviceRequest, 'threads' => $threads, 'providerMembers' => $providerMembers];
}

/**
 * Make catalog services available to the factory's digital readiness level, as IMC would
 * (ADR-025). A factory without an assessment first gets one stored (all «أ»: 10 points,
 * B4 Automation). Returns the factory's level. Requires the reference data.
 */
function availableTo(Factory $factory, string ...$serviceCodes): ReadinessCategoryCode
{
    $assessment = $factory->currentReadinessAssessment()->with('category')->first() ?? storedReadinessAssessment($factory)->load('category');
    $level = $assessment->category->code;

    foreach (CatalogService::query()->whereIn('code', $serviceCodes)->pluck('id') as $serviceId) {
        $row = ReadinessLevelService::query()->where('level', $level)->where('catalog_service_id', $serviceId)->first() ?? new ReadinessLevelService;
        $row->level = $level;
        $row->catalog_service_id = $serviceId;
        $row->is_active = true;
        $row->save();
    }

    $factory->unsetRelation('currentReadinessAssessment');

    return $level;
}

/**
 * Make the whole catalog available to the factory's readiness level (availableTo()), for
 * tests about something other than readiness-based eligibility. Returns the factory.
 */
function everyServiceAvailableTo(Factory $factory): Factory
{
    availableTo($factory, ...CatalogService::query()->pluck('code')->all());

    return $factory;
}

/**
 * Make every catalog service available to every readiness level, for tests about
 * something other than readiness-based eligibility (ServiceEligibilityTest covers it). A
 * factory still needs an assessment: the given factory gets one dated 2026-01-01, earlier
 * than any a test adds afterwards.
 */
function everyServiceAvailable(?Factory $factoryToAssess = null): void
{
    $serviceIds = CatalogService::query()->pluck('id');

    foreach (ReadinessCategoryCode::cases() as $level) {
        foreach ($serviceIds as $serviceId) {
            if (! ReadinessLevelService::query()->where('level', $level)->where('catalog_service_id', $serviceId)->exists()) {
                $row = new ReadinessLevelService;
                $row->level = $level;
                $row->catalog_service_id = $serviceId;
                $row->save();
            }
        }
    }

    if ($factoryToAssess !== null) {
        test()->travelTo('2026-01-01 08:00:00');
        storedReadinessAssessment($factoryToAssess);
        test()->travelBack();
        $factoryToAssess->unsetRelation('currentReadinessAssessment');
    }
}

/**
 * A factory in the sectors whose readiness level has every catalog service
 * (everyServiceAvailable()). Requires the reference data.
 */
function factoryWithEveryService(string ...$sectorCodes): Factory
{
    $factory = Factory::factory()->inSectors(...$sectorCodes)->create();
    everyServiceAvailable($factory);

    return $factory;
}

/**
 * A transformation plan scenario (ADR-025): an approved food-sector factory assessed at
 * 20 points (Basic), five catalog services available to Basic, and one approved
 * food-sector provider offering all of them. Seeds the reference data.
 *
 * @return array{factory: Factory, member: User, admin: User, provider: ServiceProvider, providerMember: User, services: array{a: string, b: string, c: string, d: string, e: string}}
 */
function planFixture(): array
{
    test()->seed(ReferenceDataSeeder::class);
    $services = [
        'a' => 'erp_business_applications.01',
        'b' => 'erp_business_applications.02',
        'c' => 'automation_ot.01',
        'd' => 'cloud_infrastructure.01',
        'e' => 'ai_data_analytics.01',
    ];
    $factory = Factory::factory()->inSectors('food')->create();
    $member = User::factory()->factoryMember($factory)->create();
    storedReadinessAssessment($factory, readinessChoicesForTotal(20), $member);
    availableTo($factory, ...array_values($services));
    $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering(...array_values($services))->create(['name' => 'Plan Provider']);

    return [
        'factory' => $factory,
        'member' => $member,
        'admin' => User::factory()->imcAdmin()->create(),
        'provider' => $provider,
        'providerMember' => User::factory()->providerMember($provider)->create(),
        'services' => $services,
    ];
}

/**
 * A three-stage draft: stage 1 runs A and B in parallel; stage 2 runs C after A, with D in
 * parallel; stage 3 runs E after C and D. `$assignedProviderId` is assigned to C.
 *
 * @param  array{a: string, b: string, c: string, d: string, e: string}  $services
 * @return array<string, mixed>
 */
function planDraftPayload(array $services, int $basedOnRevision = 0, ?int $assignedProviderId = null): array
{
    return [
        'based_on_revision' => $basedOnRevision,
        'title' => 'خطة التحول الرقمي',
        'summary_ar' => 'خطة من ثلاث مراحل.',
        'stages' => [
            [
                'name_ar' => 'التأسيس والتجهيز',
                'objective_ar' => 'بناء الأساس.',
                'factory_instructions_ar' => 'جهّزوا بيانات الإنتاج.',
                'internal_notes' => 'ملاحظة داخلية للمرحلة الأولى',
                'planned_start_date' => '2026-11-01',
                'planned_end_date' => '2027-01-31',
                'items' => [
                    ['service' => $services['a'], 'instructions_ar' => 'ابدأوا بنظام ERP.', 'internal_notes' => 'ملاحظة داخلية للخدمة'],
                    ['service' => $services['b']],
                ],
            ],
            [
                'name_ar' => 'التطوير والتكامل',
                'items' => [
                    ['service' => $services['c'], 'depends_on' => [$services['a']], 'service_provider_id' => $assignedProviderId],
                    ['service' => $services['d']],
                ],
            ],
            [
                'name_ar' => 'التحسين والتوسع',
                'items' => [
                    ['service' => $services['e'], 'depends_on' => [$services['c'], $services['d']]],
                ],
            ],
        ],
    ];
}

/**
 * Create, draft and publish the planDraftPayload() plan through the API as the IMC admin.
 *
 * @param  array{factory: Factory, member: User, admin: User, provider: ServiceProvider, providerMember: User, services: array{a: string, b: string, c: string, d: string, e: string}}  $fixture
 */
function publishedPlan(array $fixture, ?int $assignedProviderId = null): TransformationPlan
{
    Sanctum::actingAs($fixture['admin']);
    $planId = test()->postJson(route('api.v1.factories.transformation-plans.store', $fixture['factory']), ['title' => 'خطة التحول الرقمي'])->assertCreated()->json('data.id');
    test()->putJson(route('api.v1.transformation-plans.draft.update', $planId), planDraftPayload($fixture['services'], 0, $assignedProviderId))->assertOk();
    test()->postJson(route('api.v1.transformation-plans.publish', $planId))->assertOk();
    forgetResolvedUsers();

    return TransformationPlan::query()->findOrFail($planId);
}

/**
 * The published item for the service code in the plan.
 */
function planItem(TransformationPlan $plan, string $serviceCode): TransformationPlanItem
{
    return TransformationPlanItem::query()
        ->where('transformation_plan_id', $plan->id)
        ->whereHas('service', fn ($services) => $services->where('code', $serviceCode))
        ->sole();
}

/**
 * Store an offer version directly, as if the provider had submitted it.
 */
function offerVersion(ProviderRequest $thread, int $version, User $author, string $amount = '150000.00'): Offer
{
    $offer = new Offer;
    $offer->provider_request_id = $thread->id;
    $offer->version = $version;
    $offer->scope = "Scope v{$version}";
    $offer->deliverables = "Deliverables v{$version}";
    $offer->duration_days = 90;
    $offer->price_amount = $amount;
    $offer->currency = Offer::CURRENCY;
    $offer->author_user_id = $author->id;
    $offer->save();

    return $offer;
}

/**
 * A marketplace request whose first thread the factory has agreed by accepting the
 * provider's offer through the API. By default IMC has also approved the agreement
 * (ADR-020), which contract drafts and invoices need; pass false to leave it awaiting
 * review.
 *
 * @return array{factoryMember: User, providerMembers: list<User>, agreement: Agreement}
 */
function agreedMarketplace(int $providerCount = 2, bool $imcApproved = true): array
{
    $marketplace = marketplaceRequest($providerCount, ProviderRequestStatus::Accepted);
    $offer = offerVersion($marketplace['threads'][0], 1, $marketplace['providerMembers'][0], '250000.00');
    Sanctum::actingAs($marketplace['factoryMember']);
    test()->postJson(route('api.v1.provider-requests.offers.accept', [$marketplace['threads'][0], $offer]))->assertOk();

    $agreement = Agreement::query()->where('provider_request_id', $marketplace['threads'][0]->id)->sole();
    if ($imcApproved) {
        imcDecision($agreement, AgreementReviewStatus::Approved);
    }

    return [...$marketplace, 'agreement' => $agreement];
}

/**
 * Store IMC's decision on an agreement directly, as if a reviewer had made it.
 */
function imcDecision(Agreement $agreement, AgreementReviewStatus $decision, ?string $reason = null): AgreementReview
{
    $review = new AgreementReview;
    $review->agreement_id = $agreement->id;
    $review->decision = $decision;
    $review->reason = $reason;
    $review->decided_at = now();
    $review->save();

    return $review;
}

/**
 * Approved financial policies in effect from today (ADR-023), the rules a billing test
 * needs. They are fixtures written straight to the database: the policy lifecycle itself
 * is tested in FinancialPolicyLifecycleTest. Each key replaces the parameters of that
 * kind's global policy (merged into the defaults below); `revenue_share` is absent unless
 * given. Keys starting with "jahez." are ordinary config overrides (the payment gateway).
 *
 * @param  array<string, mixed>  $overrides
 */
function configureBilling(array $overrides = []): void
{
    $config = array_filter($overrides, fn (string $key): bool => str_starts_with($key, 'jahez.'), ARRAY_FILTER_USE_KEY);
    if ($config !== []) {
        config($config);
    }

    $defaults = [
        'invoicing' => [
            'issuer' => 'service_provider', 'payer' => 'factory', 'currency' => 'EGP', 'number_prefix' => 'JZ', 'number_padding' => 6,
            'invoice_types' => ['agreement_service'], 'manual_payments_allowed' => true, 'requires_revenue_share' => false,
        ],
        'tax' => ['prices_include_tax' => false, 'taxes' => [['code' => 'vat', 'name_ar' => 'ضريبة القيمة المضافة', 'rate_percent' => '14']], 'fees' => []],
        'payment_terms' => ['due_rule' => 'days_after_issue', 'due_days' => 30, 'partial_payments_allowed' => true],
    ];

    foreach ($defaults as $kind => $parameters) {
        $given = $overrides[$kind] ?? [];
        approvedPolicyVersion(FinancialPolicyKind::from($kind), is_array($given) ? [...$parameters, ...$given] : $parameters);
    }
    if (isset($overrides['revenue_share'])) {
        approvedPolicyVersion(FinancialPolicyKind::RevenueShare, ['method' => 'percentage', 'base' => 'subtotal_before_tax', ...$overrides['revenue_share']]);
    }
}

/**
 * An approved version of a kind's policy for a scope, in effect from $from (today by
 * default). A fixture: if the scope's policy already has an approved version from the
 * same day, its parameters are replaced in place, which only a test may do.
 *
 * @param  array<string, mixed>  $parameters
 */
function approvedPolicyVersion(
    FinancialPolicyKind $kind,
    array $parameters,
    FinancialPolicyScope $scope = FinancialPolicyScope::Global,
    ?int $scopeId = null,
    ?string $from = null,
    ?string $to = null,
): FinancialPolicyVersion {
    $normalized = PolicyParameters::normalize($kind, $parameters);
    $from ??= PolicyCalendar::today()->toDateString();
    $author = User::factory()->imcAdmin()->create();

    $policy = FinancialPolicy::query()->where('kind', $kind)->where('scope_key', $scope->key($scopeId))->first();
    if ($policy === null) {
        $policy = new FinancialPolicy;
        $policy->kind = $kind;
        $policy->scope_type = $scope;
        $policy->scope_id = $scopeId;
        $policy->scope_key = $scope->key($scopeId);
        $policy->name_ar = $kind->labelAr();
        $policy->created_by_user_id = $author->id;
        $policy->save();
    }

    $existing = $policy->versions()->where('status', FinancialPolicyVersionStatus::Approved)->whereDate('effective_from', $from)->first();
    if ($existing !== null) {
        DB::table('financial_policy_versions')->where('id', $existing->id)->update(['parameters' => json_encode($normalized), 'effective_to' => $to]);

        return $existing->refresh();
    }

    $version = new FinancialPolicyVersion;
    $version->financial_policy_id = $policy->id;
    $version->version = (int) $policy->versions()->max('version') + 1;
    $version->status = FinancialPolicyVersionStatus::Approved;
    $version->effective_from = PolicyCalendar::parse($from);
    $version->effective_to = $to === null ? null : PolicyCalendar::parse($to);
    $version->parameters = $normalized;
    $version->change_reason = 'Test fixture';
    $version->created_by_user_id = $author->id;
    $version->submitted_by_user_id = $author->id;
    $version->submitted_at = now();
    $version->decided_by_user_id = User::factory()->imcAdmin()->create()->id;
    $version->decided_at = now();
    $version->save();

    return $version;
}

/**
 * An IMC administrator holding the given individually granted permissions (ADR-023).
 */
function financeAdmin(Permission ...$permissions): User
{
    $user = User::factory()->imcAdmin()->create();
    foreach ($permissions as $permission) {
        $grant = new UserPermissionGrant;
        $grant->user_id = $user->id;
        $grant->permission = $permission->value;
        $grant->reason = 'Test fixture';
        $grant->save();
    }

    return $user->refresh();
}

/**
 * A draft invoice for a new agreement, drafted by the provider.
 *
 * @return array{factoryMember: User, providerMembers: list<User>, invoice: Invoice}
 */
function draftInvoice(): array
{
    $marketplace = agreedMarketplace();
    Sanctum::actingAs($marketplace['providerMembers'][0]);
    $id = test()->postJson(route('api.v1.agreements.invoices.store', $marketplace['agreement']))->assertCreated()->json('data.id');

    return [...$marketplace, 'invoice' => Invoice::query()->findOrFail($id)];
}

/**
 * Choice codes, in question order, whose points add up to the total: every question
 * starts at «أ» (1 point) and questions are raised in order, at most to «د» (4 points).
 * The ten-question, 1–4-point questionnaire of version 1 reaches every total from 10
 * to 40 this way.
 *
 * @return list<string>
 */
function readinessChoicesForTotal(int $total): array
{
    $codes = ['a', 'b', 'c', 'd'];
    $extra = $total - 10;
    $choices = [];
    foreach (range(1, 10) as $question) {
        $raise = max(0, min(3, $extra));
        $choices[] = $codes[$raise];
        $extra -= $raise;
    }

    return $choices;
}

/**
 * A submission payload for the current questionnaire: one choice code per question in
 * question order, or one code for every question. Requires the reference data.
 *
 * @param  string|list<string>  $choiceCodes
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function readinessPayload(string|array $choiceCodes = 'a', array $overrides = []): array
{
    $questionnaire = ReadinessQuestionnaire::query()->where('is_current', true)->with('questions.choices')->firstOrFail();
    $answers = [];
    foreach ($questionnaire->questions as $position => $question) {
        $code = is_array($choiceCodes) ? $choiceCodes[$position] : $choiceCodes;
        $answers[] = ['question_id' => $question->id, 'choice_id' => $question->choices->firstWhere('code', $code)?->id];
    }

    return ['questionnaire_version' => $questionnaire->version, 'answers' => $answers, ...$overrides];
}

/**
 * Store a completed readiness assessment for the factory directly, as if one of its
 * members had submitted the given choices (see readinessPayload()).
 *
 * @param  string|list<string>  $choiceCodes
 */
function storedReadinessAssessment(Factory $factory, string|array $choiceCodes = 'a', ?User $submitter = null): ReadinessAssessment
{
    $questionnaire = ReadinessQuestionnaire::query()->where('is_current', true)->with('questions.choices')->firstOrFail();
    $choices = [];
    foreach ($questionnaire->questions as $position => $question) {
        $choices[] = $question->choices->firstWhere('code', is_array($choiceCodes) ? $choiceCodes[$position] : $choiceCodes);
    }
    $total = array_sum(array_map(fn (ReadinessChoice $choice): int => $choice->points, $choices));

    $assessment = new ReadinessAssessment;
    $assessment->factory_id = $factory->id;
    $assessment->readiness_questionnaire_id = $questionnaire->id;
    $assessment->readiness_category_id = $questionnaire->categoryForScore($total)->id;
    $assessment->total_score = $total;
    $assessment->submitted_by_user_id = ($submitter ?? User::factory()->factoryMember($factory)->create())->id;
    $assessment->save();

    foreach ($choices as $choice) {
        $answer = new ReadinessAssessmentAnswer;
        $answer->readiness_assessment_id = $assessment->id;
        $answer->readiness_question_id = $choice->readiness_question_id;
        $answer->readiness_choice_id = $choice->id;
        $answer->points = $choice->points;
        $answer->save();
    }

    return $assessment;
}

/**
 * The locking reads the action runs, in order, as "table:update" (FOR UPDATE) or
 * "table:share" (LOCK IN SHARE MODE).
 *
 * @return list<string>
 */
function lockingReads(Closure $act): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $act();
    } finally {
        DB::disableQueryLog();
    }

    $reads = [];
    foreach (DB::getQueryLog() as $entry) {
        $mode = match (true) {
            str_ends_with($entry['query'], 'for update') => 'update',
            str_ends_with($entry['query'], 'lock in share mode') => 'share',
            default => null,
        };

        if ($mode !== null && preg_match('/from `(\w+)`/', $entry['query'], $table) === 1) {
            $reads[] = "{$table[1]}:{$mode}";
        }
    }

    return $reads;
}

/**
 * Takes the approved versions of the kinds out of effect, as a fixture (written straight
 * to the database; the lifecycle never removes an approved version that applied).
 */
function withdrawPolicies(FinancialPolicyKind ...$kinds): void
{
    $policyIds = FinancialPolicy::query()->whereIn('kind', $kinds)->pluck('id');
    DB::table('financial_policy_versions')->whereIn('financial_policy_id', $policyIds)->update(['status' => FinancialPolicyVersionStatus::Archived->value]);
}
