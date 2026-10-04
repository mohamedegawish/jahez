<?php

use App\Enums\FactoryApprovalStatus;
use App\Enums\NotificationEvent;
use App\Enums\ProviderApprovalStatus;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\ReadinessChoice;
use App\Models\ReadinessQuestion;
use App\Models\ReadinessQuestionnaire;
use App\Models\ServiceProvider;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| The whole business lifecycle through the API (owner brief "Phase 3", §10): registration,
| assessment, independent IMC reviews of the factory, the provider and its listing,
| promoted discovery, request, negotiation, IMC agreement review, contract draft, invoice,
| a reviewed sensitive change, notifications and isolation between parties.
*/

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    Storage::fake('local');
});

/**
 * The events of the user's in-app notifications, oldest first.
 *
 * @return list<string>
 */
function eventsOf(User $user): array
{
    return $user->notifications()->orderBy('created_at')->orderBy('id')->pluck('data')->pluck('event')->all();
}

it('runs the lifecycle from registration to invoice with every review step enforced', function () {
    $admin = User::factory()->imcAdmin()->create();

    // 1. A factory registers (the queued job runs synchronously in tests) and assesses itself.
    $this->postJson(route('api.v1.registration.factories'), [
        'name' => 'Nile Foods', 'size' => 'medium', 'sectors' => ['food'],
        'contact_name' => 'Mona Hassan', 'contact_email' => 'mona@nile-foods.example', 'commercial_registration_number' => '123456',
    ])->assertAccepted();
    $factory = Factory::query()->where('name', 'Nile Foods')->sole();
    $factoryMember = User::query()->where('email', 'mona@nile-foods.example')->sole();
    expect($factory->approval_status)->toBe(FactoryApprovalStatus::Pending)
        ->and(eventsOf($admin))->toContain(NotificationEvent::OrganizationRegistered->value);

    Sanctum::actingAs($factoryMember);
    $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload(readinessChoicesForTotal(27)))
        ->assertCreated()
        ->assertJsonPath('data.total_score', 27)
        ->assertJsonPath('data.category.code', 'advanced');

    // 2. A provider registers with two services: the provider and both listings wait for review.
    $this->postJson(route('api.v1.registration.service-providers'), [
        'name' => 'Delta Automation', 'sectors' => ['food'], 'services' => ['erp_business_applications.01', 'automation_ot.01'],
        'representative_name' => 'Omar Adel', 'email' => 'omar@delta.example', 'tax_registration_number' => '111-222-333',
    ])->assertAccepted();
    $provider = ServiceProvider::query()->where('name', 'Delta Automation')->sole();
    $providerMember = User::query()->where('email', 'omar@delta.example')->sole();
    expect($provider->approval_status)->toBe(ProviderApprovalStatus::Pending);

    // Nothing is visible to the factory, and the pending factory cannot send a request.
    Sanctum::actingAs($factoryMember);
    $this->getJson(route('api.v1.service-listings.index'))->assertOk()->assertJsonCount(0, 'data');
    $this->postJson(route('api.v1.service-requests.store'), ['service' => 'erp_business_applications.01', 'title' => 'ERP', 'need' => 'ERP', 'provider_ids' => [$provider->id]])
        ->assertUnprocessable();
    $this->assertDatabaseCount('service_requests', 0);

    // 3. IMC reviews each party independently, from its details.
    Sanctum::actingAs($admin);
    $this->getJson(route('api.v1.service-providers.show', $provider))
        ->assertOk()
        ->assertJsonPath('data.service_listings.0.status', 'pending')
        ->assertJsonPath('data.missing_required_fields', []);
    $this->postJson(route('api.v1.service-providers.approval', $provider), ['decision' => 'approved'])->assertOk();
    $this->postJson(route('api.v1.factories.approval', $factory), ['decision' => 'approved'])
        ->assertOk()
        ->assertJsonPath('data.current_readiness.total_score', 27);

    // An approved provider still reaches factories only through approved listings.
    Sanctum::actingAs($factoryMember);
    $this->getJson(route('api.v1.service-listings.index'))->assertOk()->assertJsonCount(0, 'data');

    // 4. IMC approves the ERP listing (and leaves the other one pending), and promotes it.
    $erpId = CatalogService::query()->where('code', 'erp_business_applications.01')->value('id');
    Sanctum::actingAs($admin);
    $this->postJson(route('api.v1.service-providers.services.review', [$provider, $erpId]), ['decision' => 'approved'])->assertOk();
    $competitor = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create(['name' => 'Aardvark ERP']);
    $this->postJson(route('api.v1.promotions.store'), ['service_provider_id' => $provider->id, 'service' => 'erp_business_applications.01', 'priority' => 50])->assertCreated();

    // 5. The factory sees eligible listings only, the promoted one first and labelled.
    Sanctum::actingAs($factoryMember);
    $this->getJson(route('api.v1.service-listings.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.provider.id', $provider->id)
        ->assertJsonPath('data.0.promotion.label', 'إعلان')
        ->assertJsonPath('data.1.provider.id', $competitor->id)
        ->assertJsonPath('data.1.promotion', null);

    // 6. The factory requests the service; the provider sees exactly its own thread.
    $requestId = $this->postJson(route('api.v1.service-requests.store'), [
        'service' => 'erp_business_applications.01', 'title' => 'ERP rollout', 'need' => 'An ERP for two plants', 'provider_ids' => [$provider->id],
    ])->assertCreated()->json('data.id');
    $threadId = $this->getJson(route('api.v1.service-requests.show', $requestId))->assertOk()->json('data.provider_requests.0.id');

    Sanctum::actingAs($providerMember);
    $this->getJson(route('api.v1.provider-requests.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $threadId);
    $competitorMember = User::factory()->providerMember($competitor)->create();

    // 7. Negotiation inside the request.
    Sanctum::actingAs($providerMember);
    $this->postJson(route('api.v1.provider-requests.accept', $threadId))->assertOk();
    $this->postJson(route('api.v1.provider-requests.messages.store', $threadId), ['body' => 'Two plants, phased.'])->assertCreated();
    $offerId = $this->postJson(route('api.v1.provider-requests.offers.store', $threadId), [
        'based_on_version' => null, 'scope' => 'ERP', 'deliverables' => 'Go-live', 'duration_days' => 120,
        'price' => ['amount' => '250000.00', 'currency' => 'EGP'],
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($competitorMember);
    $this->getJson(route('api.v1.provider-requests.show', $threadId))->assertNotFound();
    $this->getJson(route('api.v1.provider-requests.messages.index', $threadId))->assertNotFound();

    Sanctum::actingAs($factoryMember);
    $agreementId = $this->postJson(route('api.v1.provider-requests.offers.accept', [$threadId, $offerId]))->assertOk()->json('data.agreement.id')
        ?? $this->getJson(route('api.v1.provider-requests.show', $threadId))->json('data.agreement.id');

    // 8. IMC approval comes before any contract draft.
    $contractPayload = ['knowledge_transfer' => ['trainees' => 2, 'training_plan' => 'Key users']];
    $this->postJson(route('api.v1.agreements.contracts.store', $agreementId), $contractPayload)->assertConflict();
    Sanctum::actingAs($admin);
    $this->getJson(route('api.v1.provider-requests.messages.index', $threadId))->assertForbidden();
    $this->postJson(route('api.v1.agreements.review', $agreementId), ['decision' => 'approved'])->assertOk();
    Sanctum::actingAs($factoryMember);
    $this->postJson(route('api.v1.agreements.contracts.store', $agreementId), $contractPayload)->assertCreated()->assertJsonPath('data.binding', false);

    // 9. The invoice draft is tied to the agreement, its parties and its service.
    configureBilling();
    Sanctum::actingAs($providerMember);
    $invoiceId = $this->postJson(route('api.v1.agreements.invoices.store', $agreementId))->assertCreated()->json('data.id');
    $this->getJson(route('api.v1.invoices.show', $invoiceId))
        ->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.parties.factory.id', $factory->id)
        ->assertJsonPath('data.parties.provider.id', $provider->id);

    // 10. A sensitive change stays pending until IMC approves it.
    $this->postJson(route('api.v1.service-providers.change-requests.store', $provider), ['tax_registration_number' => '999-888-777'])->assertCreated();
    expect($provider->refresh()->tax_registration_number)->toBe('111-222-333');
    $changeId = $provider->openChangeRequest()->value('id');
    Sanctum::actingAs($admin);
    $this->postJson(route('api.v1.service-providers.change-requests.approve', [$provider, $changeId]))->assertOk();
    expect($provider->refresh()->tax_registration_number)->toBe('999-888-777');

    // 11. Each party was told about its own events.
    expect(eventsOf($factoryMember))->toContain(
        NotificationEvent::ReadinessAssessmentCompleted->value,
        NotificationEvent::FactoryApprovalChanged->value,
        NotificationEvent::RequestAccepted->value,
        NotificationEvent::MessageReceived->value,
        NotificationEvent::OfferSubmitted->value,
        NotificationEvent::AgreementApproved->value,
    )->and(eventsOf($providerMember))->toContain(
        NotificationEvent::ProviderApprovalChanged->value,
        NotificationEvent::ServiceListingReviewed->value,
        NotificationEvent::PromotionStarted->value,
        NotificationEvent::RequestReceived->value,
        NotificationEvent::OfferAccepted->value,
        NotificationEvent::ContractDrafted->value,
        NotificationEvent::ChangeRequestApproved->value,
    )->and(eventsOf($admin))->toContain(
        NotificationEvent::OrganizationRegistered->value,
        NotificationEvent::AgreementAwaitingReview->value,
        NotificationEvent::ChangeRequestSubmitted->value,
    )->and(eventsOf($competitorMember))->toBe([]);

    // 13. Another factory sees none of it.
    Sanctum::actingAs(User::factory()->factoryMember(Factory::factory()->inSectors('food')->create())->create());
    $this->getJson(route('api.v1.service-requests.show', $requestId))->assertNotFound();
    $this->getJson(route('api.v1.agreements.show', $agreementId))->assertNotFound();
    $this->getJson(route('api.v1.invoices.show', $invoiceId))->assertNotFound();
    $this->getJson(route('api.v1.provider-requests.offers.index', $threadId))->assertNotFound();
});

it('classifies every boundary total exactly as the framework document states', function (int $total, string $category) {
    $factory = Factory::factory()->inSectors('food')->create();
    Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

    $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload(readinessChoicesForTotal($total)))
        ->assertCreated()
        ->assertJsonPath('data.total_score', $total)
        ->assertJsonPath('data.category.code', $category);
})->with([
    [10, 'b4_automation'], [17, 'b4_automation'],
    [18, 'basic'], [25, 'basic'],
    [26, 'advanced'], [33, 'advanced'],
    [34, 'smart'], [40, 'smart'],
]);

it('keeps the assessment reference data whole when the seeder runs again', function () {
    $this->seed(ReferenceDataSeeder::class);

    $questionnaire = ReadinessQuestionnaire::query()->where('is_current', true)->sole();
    expect(ReadinessQuestionnaire::query()->count())->toBe(1)
        ->and($questionnaire->pillars()->count())->toBe(5)
        ->and(ReadinessQuestion::query()->count())->toBe(10)
        ->and(ReadinessChoice::query()->count())->toBe(40)
        ->and(ReadinessChoice::query()->distinct()->pluck('points')->sort()->values()->all())->toBe([1, 2, 3, 4])
        ->and($questionnaire->categories()->orderBy('min_score')->get()->map(fn ($c): array => [$c->code->value, $c->min_score, $c->max_score])->all())
        ->toBe([['b4_automation', 10, 17], ['basic', 18, 25], ['advanced', 26, 33], ['smart', 34, 40]]);
});
