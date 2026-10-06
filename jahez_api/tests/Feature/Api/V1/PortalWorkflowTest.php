<?php

use App\Enums\AgreementReviewStatus;
use App\Enums\AuditEvent;
use App\Enums\NotificationEvent;
use App\Enums\ProviderApprovalStatus;
use App\Enums\ProviderRequestStatus;
use App\Enums\ServiceListingStatus;
use App\Models\AuditLog;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\Invoice;
use App\Models\ProviderRequest;
use App\Models\ServicePromotion;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Notifications\PlatformEventMail;
use App\Notifications\PlatformNotifier;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| Phase 2 portals (ADR-020): IMC review of agreements, notifications, read marks,
| promoted listings, reports, factory legal changes and account settings.
*/

/**
 * A running promotion of the provider's listing of the service.
 */
function promote(ServiceProvider $provider, string $serviceCode, int $priority = 10): ServicePromotion
{
    $promotion = new ServicePromotion;
    $promotion->service_provider_id = $provider->id;
    $promotion->catalog_service_id = (int) CatalogService::query()->where('code', $serviceCode)->value('id');
    $promotion->priority = $priority;
    $promotion->starts_at = now()->subDay();
    $promotion->save();

    return $promotion;
}

describe('IMC review of agreements (scenario C)', function () {
    it('keeps a new agreement awaiting IMC review and refuses a contract draft or an invoice until approval', function () {
        configureBilling();
        ['factoryMember' => $factoryMember, 'providerMembers' => $providerMembers, 'agreement' => $agreement] = agreedMarketplace(imcApproved: false);

        Sanctum::actingAs($factoryMember);
        $this->getJson(route('api.v1.agreements.show', $agreement))
            ->assertOk()
            ->assertJsonPath('data.imc_review.status', 'pending')
            ->assertJsonPath('data.next_step', 'awaiting_imc_review')
            ->assertJsonPath('data.binding', false);
        $this->postJson(route('api.v1.agreements.contracts.store', $agreement), [
            'knowledge_transfer' => ['trainees' => 2, 'training_plan' => 'Plan'],
        ])->assertConflict();

        Sanctum::actingAs($providerMembers[0]);
        $this->postJson(route('api.v1.agreements.invoices.store', $agreement))->assertConflict();
        expect(Invoice::query()->count())->toBe(0);
    });

    it('lets IMC approve once, which allows the contract draft, records the decision and notifies both parties', function () {
        ['factoryMember' => $factoryMember, 'providerMembers' => $providerMembers, 'agreement' => $agreement] = agreedMarketplace(imcApproved: false);
        $admin = User::factory()->imcAdmin()->create();

        Sanctum::actingAs($admin);
        $this->postJson(route('api.v1.agreements.review', $agreement), ['decision' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.imc_review.status', 'approved')
            ->assertJsonPath('data.next_step', 'contract_draft');
        $this->postJson(route('api.v1.agreements.review', $agreement), ['decision' => 'rejected', 'reason' => 'Late'])->assertConflict();

        expect(AuditLog::query()->where('event', AuditEvent::AgreementReviewed)->sole()->metadata)->toEqual(['decision' => 'approved', 'reason' => null])
            ->and($factoryMember->notifications()->where('data->event', NotificationEvent::AgreementApproved->value)->count())->toBe(1)
            ->and($providerMembers[0]->notifications()->where('data->event', NotificationEvent::AgreementApproved->value)->count())->toBe(1)
            ->and($providerMembers[1]->notifications()->where('data->event', NotificationEvent::AgreementApproved->value)->count())->toBe(0);

        Sanctum::actingAs($factoryMember);
        $this->postJson(route('api.v1.agreements.contracts.store', $agreement), [
            'knowledge_transfer' => ['trainees' => 2, 'training_plan' => 'Plan'],
        ])->assertCreated()->assertJsonPath('data.binding', false);
    });

    it('requires a reason to reject, and a rejected agreement gets no contract draft', function () {
        ['factoryMember' => $factoryMember, 'agreement' => $agreement] = agreedMarketplace(imcApproved: false);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->postJson(route('api.v1.agreements.review', $agreement), ['decision' => 'rejected'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson(route('api.v1.agreements.review', $agreement), ['decision' => 'pending'])->assertUnprocessable();
        $this->postJson(route('api.v1.agreements.review', $agreement), ['decision' => 'rejected', 'reason' => 'Scope unclear'])
            ->assertOk()
            ->assertJsonPath('data.imc_review.reason', 'Scope unclear');

        Sanctum::actingAs($factoryMember);
        $this->postJson(route('api.v1.agreements.contracts.store', $agreement), [
            'knowledge_transfer' => ['trainees' => 2, 'training_plan' => 'Plan'],
        ])->assertConflict();
    });

    it('refuses the decision to the parties (403) and to anyone else (404)', function () {
        ['factoryMember' => $factoryMember, 'providerMembers' => $providerMembers, 'agreement' => $agreement] = agreedMarketplace(imcApproved: false);

        foreach ([[$factoryMember, 403], [$providerMembers[0], 403], [$providerMembers[1], 404]] as [$user, $status]) {
            Sanctum::actingAs($user);
            $this->postJson(route('api.v1.agreements.review', $agreement), ['decision' => 'approved'])->assertStatus($status);
        }
        expect($agreement->review()->exists())->toBeFalse();
    });

    it('lists the IMC queue of agreements awaiting review', function () {
        ['agreement' => $pending] = agreedMarketplace(imcApproved: false);
        ['agreement' => $approved] = agreedMarketplace();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        expect($this->getJson(route('api.v1.agreements.index', ['filter' => ['review_status' => 'pending']]))->json('data.*.id'))->toBe([$pending->id])
            ->and($this->getJson(route('api.v1.agreements.index', ['filter' => ['review_status' => 'approved']]))->json('data.*.id'))->toBe([$approved->id]);
    });
});

describe('notifications (scenario G)', function () {
    it('notifies only the chosen providers of a new request, in-app, and queues an email after the commit', function () {
        Queue::fake();
        $this->seed(ReferenceDataSeeder::class);
        $factoryMember = User::factory()->factoryMember(factoryWithEveryService('food'))->create();
        $chosen = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
        $other = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
        $chosenMember = User::factory()->providerMember($chosen)->create();
        $otherMember = User::factory()->providerMember($other)->create();

        Sanctum::actingAs($factoryMember);
        $this->postJson(route('api.v1.service-requests.store'), [
            'service' => 'erp_business_applications.01',
            'title' => 'ERP',
            'need' => 'Need',
            'provider_ids' => [$chosen->id],
        ])->assertCreated();

        $thread = ProviderRequest::query()->sole();
        $notification = $chosenMember->notifications()->sole();
        expect($notification->data['event'])->toBe(NotificationEvent::RequestReceived->value)
            ->and($notification->data['link'])->toBe("/provider/requests/{$thread->id}")
            ->and($otherMember->notifications()->count())->toBe(0)
            ->and($factoryMember->notifications()->count())->toBe(0);
        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof PlatformEventMail
            && $job->notifiables->contains(fn (User $user): bool => $user->is($chosenMember)));
    });

    it('tells the other side about a message without its text, and skips the email when the recipient turned it off', function () {
        Queue::fake();
        ['factoryMember' => $factoryMember, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        $providerMembers[0]->forceFill(['email_notifications' => false])->save();

        Sanctum::actingAs($factoryMember);
        $this->postJson(route('api.v1.provider-requests.messages.store', $threads[0]), ['body' => 'Secret commercial detail'])->assertCreated();

        $notification = $providerMembers[0]->notifications()->sole();
        expect(json_encode($notification->data, JSON_UNESCAPED_UNICODE))->not->toContain('Secret commercial detail')
            ->and($factoryMember->notifications()->count())->toBe(0);
        Queue::assertNotPushed(SendQueuedNotifications::class);
    });

    it('stores one notification per recipient when the same event is processed twice', function () {
        $this->seed(ReferenceDataSeeder::class);
        $provider = ServiceProvider::factory()->approved()->create();
        $member = User::factory()->providerMember($provider)->create();

        PlatformNotifier::providerMembers($provider->id, NotificationEvent::RequestReceived, 'test.event.1', 'Body', '/x');
        PlatformNotifier::providerMembers($provider->id, NotificationEvent::RequestReceived, 'test.event.1', 'Body', '/x');

        expect($member->notifications()->count())->toBe(1);
    });

    it('lists, counts and marks the account\'s own notifications, and hides other accounts\' ones', function () {
        $this->seed(ReferenceDataSeeder::class);
        $provider = ServiceProvider::factory()->approved()->create();
        $member = User::factory()->providerMember($provider)->create();
        $stranger = User::factory()->providerMember()->create();
        PlatformNotifier::providerMembers($provider->id, NotificationEvent::RequestReceived, 'a', 'First', '/a');
        PlatformNotifier::providerMembers($provider->id, NotificationEvent::MessageReceived, 'b', 'Second', '/b');
        $id = $member->notifications()->firstOrFail()->id;

        Sanctum::actingAs($stranger);
        $this->postJson(route('api.v1.notifications.read', $id))->assertNotFound();

        Sanctum::actingAs($member);
        $this->getJson(route('api.v1.notifications.index'))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.unread_count', 2);
        $this->postJson(route('api.v1.notifications.read', $id))->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson(route('api.v1.notifications.index', ['filter' => ['unread' => 1]]))->assertJsonCount(1, 'data')->assertJsonPath('meta.unread_count', 1);
        $this->postJson(route('api.v1.notifications.read-all'))->assertOk();
        expect(DatabaseNotification::query()->whereNull('read_at')->count())->toBe(0);
    });

    it('notifies a provider of IMC\'s approval decision', function () {
        $this->seed(ReferenceDataSeeder::class);
        $provider = ServiceProvider::factory()->create();
        $member = User::factory()->providerMember($provider)->create();

        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $this->postJson(route('api.v1.service-providers.approval', $provider), ['decision' => 'approved'])->assertOk();

        expect($member->notifications()->sole()->data['event'])->toBe(NotificationEvent::ProviderApprovalChanged->value);
    });
});

describe('unread messages', function () {
    it('counts the other side\'s unread messages per participant until they mark the thread read', function () {
        ['factoryMember' => $factoryMember, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);

        Sanctum::actingAs($factoryMember);
        $this->postJson(route('api.v1.provider-requests.messages.store', $threads[0]), ['body' => 'One'])->assertCreated();
        $this->postJson(route('api.v1.provider-requests.messages.store', $threads[0]), ['body' => 'Two'])->assertCreated();
        $this->getJson(route('api.v1.provider-requests.show', $threads[0]))->assertJsonPath('data.unread_messages_count', 0);

        Sanctum::actingAs($providerMembers[0]);
        $this->getJson(route('api.v1.provider-requests.index', ['filter' => ['unread' => 1]]))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.unread_messages_count', 2);
        $this->postJson(route('api.v1.provider-requests.read', $threads[0]))->assertOk()->assertJsonPath('data.unread_messages_count', 0);
        $this->getJson(route('api.v1.provider-requests.index', ['filter' => ['unread' => 1]]))->assertJsonCount(0, 'data');
    });

    it('refuses read marks to IMC (403) and to a competitor (404)', function () {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(2, ProviderRequestStatus::Accepted);

        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $this->postJson(route('api.v1.provider-requests.read', $threads[0]))->assertForbidden();
        Sanctum::actingAs($providerMembers[1]);
        $this->postJson(route('api.v1.provider-requests.read', $threads[0]))->assertNotFound();
    });

    it('searches a provider\'s inbox by request title or factory name', function () {
        ['threads' => $threads, 'providerMembers' => $providerMembers, 'serviceRequest' => $serviceRequest] = marketplaceRequest(1);

        Sanctum::actingAs($providerMembers[0]);
        expect($this->getJson(route('api.v1.provider-requests.index', ['search' => mb_substr($serviceRequest->title, 0, 4)]))->json('data.*.id'))->toBe([$threads[0]->id])
            ->and($this->getJson(route('api.v1.provider-requests.index', ['search' => 'zzz-no-match']))->json('data'))->toBe([]);
        $this->getJson(route('api.v1.provider-requests.index', ['sort' => 'random']))->assertUnprocessable();
    });
});

describe('service listings and promotions (scenario E)', function () {
    beforeEach(function () {
        $this->seed(ReferenceDataSeeder::class);
        $this->factoryMember = User::factory()->factoryMember(factoryWithEveryService('food'))->create();
    });

    it('shows a factory only eligible listings, promoted ones first and labelled, each listing once', function () {
        $ordinary = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create(['name' => 'A ordinary']);
        $promoted = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create(['name' => 'Z promoted']);
        promote($promoted, 'erp_business_applications.01');

        Sanctum::actingAs($this->factoryMember);
        $response = $this->getJson(route('api.v1.service-listings.index'))->assertOk();

        expect($response->json('data.*.provider.name'))->toBe(['Z promoted', 'A ordinary'])
            ->and($response->json('data.0.promotion.label'))->toBe('إعلان')
            ->and($response->json('data.1.promotion'))->toBeNull()
            ->and($response->json('data.0.provider'))->not->toHaveKey('approval_status')
            ->and($ordinary->id)->toBeInt();
    });

    it('never shows a promoted listing the factory is not eligible for: another sector, pending or suspended providers', function (Closure $makeProvider) {
        promote($makeProvider(), 'erp_business_applications.01', 999);

        Sanctum::actingAs($this->factoryMember);
        expect($this->getJson(route('api.v1.service-listings.index'))->json('data'))->toBe([]);
    })->with([
        'other sector' => [fn () => ServiceProvider::factory()->approved()->inSectors('chemical')->offering('erp_business_applications.01')->create()],
        'pending provider' => [fn () => ServiceProvider::factory()->inSectors('food')->offering('erp_business_applications.01')->create()],
        'suspended provider' => [fn () => ServiceProvider::factory()->withApprovalStatus(ProviderApprovalStatus::Suspended)->inSectors('food')->offering('erp_business_applications.01')->create()],
        'pending listing' => [fn () => ServiceProvider::factory()->approved()->inSectors('food')->listing(ServiceListingStatus::Pending, 'erp_business_applications.01')->create()],
        'rejected listing' => [fn () => ServiceProvider::factory()->approved()->inSectors('food')->listing(ServiceListingStatus::Rejected, 'erp_business_applications.01')->create()],
    ]);

    it('puts no legal or contact detail of the provider on a listing card', function () {
        ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create([
            'email' => 'sales@provider.example.test',
            'phone' => '+201111111111',
            'commercial_registration_number' => 'CR-778899',
            'tax_registration_number' => 'TX-112233',
            'address' => '5 Secret Street',
        ]);

        foreach ([$this->factoryMember, User::factory()->imcAdmin()->create()] as $viewer) {
            Sanctum::actingAs($viewer);
            $listing = $this->getJson(route('api.v1.service-listings.index'))->assertOk()->json('data.0');

            expect(array_keys($listing['provider']))->not->toContain('email', 'phone', 'commercial_registration_number', 'tax_registration_number', 'address', 'legal_name')
                ->and(json_encode($listing))->not->toContain('CR-778899')
                ->and(json_encode($listing))->not->toContain('sales@provider.example.test');
        }
    });

    it('drops the label once a promotion ends or before it starts', function () {
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
        $promotion = promote($provider, 'erp_business_applications.01');
        $admin = User::factory()->imcAdmin()->create();

        Sanctum::actingAs($admin);
        $this->postJson(route('api.v1.promotions.end', $promotion))->assertOk()->assertJsonPath('data.state', 'ended');
        $this->postJson(route('api.v1.promotions.end', $promotion))->assertConflict();

        Sanctum::actingAs($this->factoryMember);
        expect($this->getJson(route('api.v1.service-listings.index'))->json('data.0.promotion'))->toBeNull();
    });

    it('lets IMC create a promotion only for a service the provider offers, and refuses members', function () {
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();

        Sanctum::actingAs($this->factoryMember);
        $this->postJson(route('api.v1.promotions.store'), ['service_provider_id' => $provider->id, 'service' => 'erp_business_applications.01'])->assertForbidden();

        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $this->postJson(route('api.v1.promotions.store'), ['service_provider_id' => $provider->id, 'service' => 'erp_business_applications.02'])
            ->assertUnprocessable()->assertJsonValidationErrors('service');
        $this->postJson(route('api.v1.promotions.store'), ['service_provider_id' => $provider->id, 'service' => 'erp_business_applications.01', 'priority' => 5, 'headline' => 'عرض'])
            ->assertCreated()->assertJsonPath('data.state', 'active');
        expect(AuditLog::query()->where('event', AuditEvent::PromotionCreated)->count())->toBe(1);
    });

    it('shows a provider its own listings with its approval status', function () {
        $provider = ServiceProvider::factory()->inSectors('food')->offering('erp_business_applications.01')->create();
        ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();

        Sanctum::actingAs(User::factory()->providerMember($provider)->create());
        $response = $this->getJson(route('api.v1.service-listings.index'))->assertOk();

        expect($response->json('data.*.provider.id'))->toBe([$provider->id])
            ->and($response->json('data.0.provider.approval_status'))->toBe('pending');
    });

    it('refuses the recommended filter before the factory has a readiness assessment', function () {
        Sanctum::actingAs(User::factory()->factoryMember(Factory::factory()->inSectors('food')->create())->create());
        $this->getJson(route('api.v1.service-listings.index', ['filter' => ['recommended' => 1]]))->assertUnprocessable();
        $this->getJson(route('api.v1.service-listings.index'))->assertJsonPath('meta.readiness', null)->assertJsonPath('meta.has_sectors', true);
    });
});

describe('reports and isolation (scenario H)', function () {
    it('counts only the caller\'s own threads; a competitor sees nothing of them', function () {
        ['providerMembers' => $providerMembers] = marketplaceRequest(2);
        ProviderRequest::query()->orderBy('id')->firstOrFail();
        $outsider = User::factory()->providerMember(ServiceProvider::factory()->approved()->create())->create();

        Sanctum::actingAs($providerMembers[0]);
        $this->getJson(route('api.v1.reports.marketplace'))
            ->assertOk()
            ->assertJsonPath('data.requests.total', 1)
            ->assertJsonPath('data.requests.by_status.pending', 1)
            ->assertJsonPath('data.conversion.agreed_rate_percent', 0);

        Sanctum::actingAs($outsider);
        $this->getJson(route('api.v1.reports.marketplace'))
            ->assertJsonPath('data.requests.total', 0)
            ->assertJsonPath('data.conversion.agreed_rate_percent', null)
            ->assertJsonPath('data.response_time.average_hours', null);
    });

    it('validates the period', function () {
        ['providerMembers' => $providerMembers] = marketplaceRequest(1);
        Sanctum::actingAs($providerMembers[0]);

        $this->getJson(route('api.v1.reports.marketplace', ['from' => '2026-10-01', 'to' => '2026-09-01']))->assertUnprocessable();
        $this->getJson(route('api.v1.reports.marketplace', ['from' => '2020-01-01', 'to' => '2026-01-01']))->assertUnprocessable();
    });

    it('keeps another provider\'s thread, agreement and invoice out of reach (404)', function () {
        configureBilling();
        ['providerMembers' => $providerMembers, 'invoice' => $invoice] = draftInvoice();
        $thread = ProviderRequest::query()->orderBy('id')->firstOrFail();

        Sanctum::actingAs($providerMembers[1]);
        $this->getJson(route('api.v1.provider-requests.show', $thread))->assertNotFound();
        $this->getJson(route('api.v1.provider-requests.messages.index', $thread))->assertNotFound();
        $this->getJson(route('api.v1.agreements.show', $invoice->agreement_id))->assertNotFound();
        $this->getJson(route('api.v1.invoices.show', $invoice))->assertNotFound();
    });
});

describe('factory legal changes (scenario F)', function () {
    beforeEach(function () {
        $this->seed(ReferenceDataSeeder::class);
        $this->factory = Factory::factory()->inSectors('food')->create(['legal_name' => 'Old Legal Co', 'tax_registration_number' => null]);
        $this->member = User::factory()->factoryMember($this->factory)->create();
    });

    it('saves ordinary fields and fills an empty legal field directly, but refuses to replace a recorded one', function () {
        Sanctum::actingAs($this->member);

        $this->patchJson(route('api.v1.factories.update', $this->factory), ['contact_name' => 'Mona', 'tax_registration_number' => '123-456'])->assertOk();
        $this->patchJson(route('api.v1.factories.update', $this->factory), ['legal_name' => 'New Legal Co'])->assertUnprocessable()->assertJsonValidationErrors('legal_name');
        $this->patchJson(route('api.v1.factories.update', $this->factory), ['legal_name' => 'Old Legal Co', 'contact_phone' => '0100000000'])->assertOk();

        expect($this->factory->refresh()->legal_name)->toBe('Old Legal Co')
            ->and($this->factory->tax_registration_number)->toBe('123-456')
            ->and($this->factory->contact_name)->toBe('Mona');
    });

    it('keeps the recorded value while the change request is pending and applies it only on IMC approval', function () {
        Sanctum::actingAs($this->member);
        $id = $this->post(route('api.v1.factories.change-requests.store', $this->factory), ['legal_name' => 'New Legal Co'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');
        $this->post(route('api.v1.factories.change-requests.store', $this->factory), ['legal_name' => 'Other'], ['Accept' => 'application/json'])->assertConflict();
        expect($this->factory->refresh()->legal_name)->toBe('Old Legal Co');

        $this->postJson(route('api.v1.factories.change-requests.approve', [$this->factory, $id]))->assertForbidden();

        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $this->postJson(route('api.v1.factories.change-requests.approve', [$this->factory, $id]))->assertOk()->assertJsonPath('data.status', 'approved');

        expect($this->factory->refresh()->legal_name)->toBe('New Legal Co')
            ->and($this->member->notifications()->where('data->event', NotificationEvent::ChangeRequestApproved->value)->count())->toBe(1)
            ->and(AuditLog::query()->where('event', AuditEvent::FactoryChangeRequestApproved)->count())->toBe(1);
    });

    it('keeps the recorded value when IMC rejects, with the reason shown to the factory', function () {
        Sanctum::actingAs($this->member);
        $id = $this->post(route('api.v1.factories.change-requests.store', $this->factory), ['legal_name' => 'New Legal Co'], ['Accept' => 'application/json'])->json('data.id');

        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $this->postJson(route('api.v1.factories.change-requests.reject', [$this->factory, $id]))->assertUnprocessable();
        $this->postJson(route('api.v1.factories.change-requests.reject', [$this->factory, $id]), ['reason' => 'Attach the registry extract'])->assertOk();

        Sanctum::actingAs($this->member);
        $this->getJson(route('api.v1.factories.change-requests.index', $this->factory))
            ->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.review_reason', 'Attach the registry extract');
        expect($this->factory->refresh()->legal_name)->toBe('Old Legal Co');
    });

    it('returns 404 to another factory\'s member', function () {
        Sanctum::actingAs(User::factory()->factoryMember()->create());

        $this->post(route('api.v1.factories.change-requests.store', $this->factory), ['legal_name' => 'X'], ['Accept' => 'application/json'])->assertNotFound();
        $this->getJson(route('api.v1.factories.change-requests.index', $this->factory))->assertNotFound();
    });
});

describe('account settings', function () {
    it('lets an account rename itself and turn email notifications off, never change its role or email', function () {
        $user = User::factory()->factoryMember()->create(['email' => 'me@example.test']);
        Sanctum::actingAs($user);

        $this->patchJson(route('api.v1.me.update'), ['name' => 'New Name', 'email_notifications' => false, 'role' => 'imc_admin', 'email' => 'x@example.test'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email_notifications', false)
            ->assertJsonPath('data.role', 'factory_member')
            ->assertJsonPath('data.email', 'me@example.test');
    });
});

it('keeps the agreement review status on each thread of a factory\'s request', function () {
    ['factoryMember' => $factoryMember, 'agreement' => $agreement] = agreedMarketplace(imcApproved: false);
    imcDecision($agreement, AgreementReviewStatus::Rejected, 'No');

    Sanctum::actingAs($factoryMember);
    $threads = $this->getJson(route('api.v1.service-requests.show', $agreement->service_request_id))->json('data.provider_requests');

    expect(collect($threads)->firstWhere('id', $agreement->provider_request_id)['agreement']['review_status'])->toBe('rejected');
});
