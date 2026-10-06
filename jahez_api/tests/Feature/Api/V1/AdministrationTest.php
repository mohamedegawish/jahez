<?php

use App\Enums\AuditEvent;
use App\Enums\FactoryApprovalStatus;
use App\Enums\NotificationEvent;
use App\Enums\ProviderApprovalStatus;
use App\Enums\ServiceListingStatus;
use App\Models\AuditLog;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\ReadinessAssessment;
use App\Models\ServicePromotion;
use App\Models\ServiceProvider;
use App\Models\ServiceRequest;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/*
| Phase 3 administration (ADR-021): factory approval, per-service listing review,
| corrections requested from providers, readiness results and analytics, and the
| notifications of these events.
*/

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

/**
 * The status of a provider's listing of the service, as stored.
 */
function listingStatus(ServiceProvider $provider, string $serviceCode): ?string
{
    return DB::table('catalog_service_service_provider')
        ->where('service_provider_id', $provider->id)
        ->where('catalog_service_id', CatalogService::query()->where('code', $serviceCode)->value('id'))
        ->value('status');
}

/**
 * A request payload from a food factory to the providers for the ERP service.
 *
 * @param  list<int>  $providerIds
 * @return array<string, mixed>
 */
function erpRequest(array $providerIds): array
{
    return ['service' => 'erp_business_applications.01', 'title' => 'ERP', 'need' => 'An ERP system', 'provider_ids' => $providerIds];
}

describe('factory approval', function () {
    it('lets only an approved factory send requests, and IMC approval opens it', function () {
        $factory = Factory::factory()->withApprovalStatus(FactoryApprovalStatus::Pending)->inSectors('food')->create();
        availableTo($factory, 'erp_business_applications.01');
        $member = User::factory()->factoryMember($factory)->create();
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();

        Sanctum::actingAs($member);
        $this->postJson(route('api.v1.service-requests.store'), erpRequest([$provider->id]))->assertConflict();
        $this->getJson(route('api.v1.factories.show', $factory))
            ->assertOk()
            ->assertJsonPath('data.approval.status', 'pending')
            ->assertJsonPath('data.approval.may_send_requests', false);
        expect(ServiceRequest::query()->count())->toBe(0);

        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $this->postJson(route('api.v1.factories.approval', $factory), ['decision' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.approval.status', 'approved');

        Sanctum::actingAs($member);
        $this->postJson(route('api.v1.service-requests.store'), erpRequest([$provider->id]))->assertCreated();
        expect($member->notifications()->where('data->event', NotificationEvent::FactoryApprovalChanged->value)->count())->toBe(1);
    });

    it('lets an unapproved factory send requests when the gate is turned off', function () {
        config(['jahez.factories.approval_required' => false]);
        $factory = Factory::factory()->withApprovalStatus(FactoryApprovalStatus::Pending)->inSectors('food')->create();
        availableTo($factory, 'erp_business_applications.01');
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();

        Sanctum::actingAs(User::factory()->factoryMember($factory)->create());
        $this->postJson(route('api.v1.service-requests.store'), erpRequest([$provider->id]))->assertCreated();
    });

    it('never changes the readiness assessment, score or category', function () {
        $factory = Factory::factory()->withApprovalStatus(FactoryApprovalStatus::Pending)->inSectors('food')->create();
        $assessment = storedReadinessAssessment($factory, 'c');
        $before = $assessment->only(['total_score', 'readiness_category_id', 'readiness_questionnaire_id']);

        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $this->postJson(route('api.v1.factories.approval', $factory), ['decision' => 'changes_requested', 'reason' => 'Upload the registration'])->assertOk();
        $this->postJson(route('api.v1.factories.approval', $factory), ['decision' => 'approved'])->assertOk()
            ->assertJsonPath('data.current_readiness.total_score', $before['total_score']);

        expect(ReadinessAssessment::query()->sole()->only(['total_score', 'readiness_category_id', 'readiness_questionnaire_id']))->toEqual($before);
    });

    it('allows only the documented transitions and requires a reason except for approval', function () {
        $factory = Factory::factory()->withApprovalStatus(FactoryApprovalStatus::Pending)->inSectors('food')->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->postJson(route('api.v1.factories.approval', $factory), ['decision' => 'rejected'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson(route('api.v1.factories.approval', $factory), ['decision' => 'pending'])->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->postJson(route('api.v1.factories.approval', $factory), ['decision' => 'suspended', 'reason' => 'x'])->assertConflict();
        $this->postJson(route('api.v1.factories.approval', $factory), ['decision' => 'approved'])->assertOk();
        $this->postJson(route('api.v1.factories.approval', $factory), ['decision' => 'approved'])->assertConflict();

        expect(AuditLog::query()->where('event', AuditEvent::FactoryApprovalChanged)->sole()->metadata)
            ->toEqual(['from' => 'pending', 'to' => 'approved', 'reason' => null]);
    });

    it('refuses approval while a configured required field is empty', function () {
        $factory = Factory::factory()->withApprovalStatus(FactoryApprovalStatus::Pending)->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->postJson(route('api.v1.factories.approval', $factory), ['decision' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decision');
        expect($factory->refresh()->approval_status)->toBe(FactoryApprovalStatus::Pending);
    });

    it('sends a factory asked for corrections back to review at its request, and tells IMC', function () {
        $factory = Factory::factory()->withApprovalStatus(FactoryApprovalStatus::ChangesRequested)->inSectors('food')->create();
        $member = User::factory()->factoryMember($factory)->create();
        $admin = User::factory()->imcAdmin()->create();

        Sanctum::actingAs($member);
        $this->postJson(route('api.v1.factories.review-request', $factory), ['note' => 'Uploaded'])
            ->assertOk()
            ->assertJsonPath('data.approval.status', 'pending');
        $this->postJson(route('api.v1.factories.review-request', $factory))->assertConflict();

        expect($admin->notifications()->where('data->event', NotificationEvent::ReviewRequested->value)->count())->toBe(1)
            ->and(AuditLog::query()->where('event', AuditEvent::FactoryReviewRequested)->sole()->metadata)
            ->toEqual(['from' => 'changes_requested', 'to' => 'pending', 'note' => 'Uploaded']);
    });

    it('lets only IMC decide, hides other factories and keeps members off the decision', function () {
        $factory = Factory::factory()->withApprovalStatus(FactoryApprovalStatus::Pending)->create();
        $other = Factory::factory()->create();

        Sanctum::actingAs(User::factory()->factoryMember($factory)->create());
        $this->postJson(route('api.v1.factories.approval', $factory), ['decision' => 'approved'])->assertForbidden();
        $this->postJson(route('api.v1.factories.review-request', $other))->assertNotFound();

        Sanctum::actingAs(User::factory()->providerMember()->create());
        $this->postJson(route('api.v1.factories.approval', $factory), ['decision' => 'approved'])->assertNotFound();
    });

    it('approves factories IMC creates and keeps self-registered ones pending', function () {
        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $this->postJson(route('api.v1.factories.store'), ['name' => 'Created by IMC', 'sectors' => ['food']])
            ->assertCreated()
            ->assertJsonPath('data.approval.status', 'approved');

        expect((new Factory(['name' => 'New']))->approval_status)->toBe(FactoryApprovalStatus::Pending);
    });

    it('filters and sorts the IMC list by approval and readiness', function () {
        $pending = Factory::factory()->withApprovalStatus(FactoryApprovalStatus::Pending)->create(['name' => 'Pending one']);
        $assessed = Factory::factory()->create(['name' => 'Assessed one']);
        storedReadinessAssessment($assessed, 'd');
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->getJson(route('api.v1.factories.index', ['filter' => ['approval_status' => 'pending']]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pending->id);
        $this->getJson(route('api.v1.factories.index', ['filter' => ['readiness' => 'smart']]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $assessed->id);
        $this->getJson(route('api.v1.factories.index', ['filter' => ['readiness' => 'none']]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pending->id);
        $this->getJson(route('api.v1.factories.index', ['sort' => 'name']))
            ->assertOk()->assertJsonPath('data.0.id', $assessed->id);
        $this->getJson(route('api.v1.factories.index', ['sort' => 'id;drop']))->assertUnprocessable();
    });
});

describe('service listing review', function () {
    it('keeps a newly listed service pending and away from factories until IMC approves it', function () {
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->create();
        $providerMember = User::factory()->providerMember($provider)->create();
        $reviewer = User::factory()->imcAdmin()->create();
        $factoryMember = User::factory()->factoryMember(factoryWithEveryService('food'))->create();

        Sanctum::actingAs($providerMember);
        $this->patchJson(route('api.v1.service-providers.update', $provider), ['services' => ['erp_business_applications.01']])
            ->assertOk()
            ->assertJsonPath('data.service_listings.0.status', 'pending');
        expect($reviewer->notifications()->where('data->event', NotificationEvent::ServiceListingSubmitted->value)->count())->toBe(1);

        Sanctum::actingAs($factoryMember);
        $this->getJson(route('api.v1.service-listings.index'))->assertOk()->assertJsonCount(0, 'data');
        // ADR-025: a provider with no approved listing of a service the factory may use is not in its directory.
        $this->getJson(route('api.v1.provider-directory.show', $provider))->assertNotFound();
        $this->postJson(route('api.v1.service-requests.store'), erpRequest([$provider->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('provider_ids');

        Sanctum::actingAs($reviewer);
        $this->postJson(route('api.v1.service-providers.services.review', [$provider, CatalogService::query()->where('code', 'erp_business_applications.01')->value('id')]), ['decision' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.service_listings.0.status', 'approved')
            ->assertJsonPath('data.approval.status', 'approved');

        Sanctum::actingAs($factoryMember);
        $this->getJson(route('api.v1.service-listings.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.review');
        $this->postJson(route('api.v1.service-requests.store'), erpRequest([$provider->id]))->assertCreated();

        expect($providerMember->notifications()->where('data->event', NotificationEvent::ServiceListingReviewed->value)->count())->toBe(1)
            ->and(AuditLog::query()->where('event', AuditEvent::ServiceListingReviewed)->sole()->metadata)
            ->toEqual(['service' => 'erp_business_applications.01', 'from' => 'pending', 'to' => 'approved', 'reason' => null]);
    });

    it('keeps the status of listings the provider keeps when it edits its services', function () {
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
        Sanctum::actingAs(User::factory()->providerMember($provider)->create());

        $this->patchJson(route('api.v1.service-providers.update', $provider), ['services' => ['erp_business_applications.01', 'automation_ot.01']])->assertOk();

        expect(listingStatus($provider, 'erp_business_applications.01'))->toBe('approved')
            ->and(listingStatus($provider, 'automation_ot.01'))->toBe('pending');
    });

    it('allows only documented transitions, requires reasons and refuses a service that is not listed', function () {
        $provider = ServiceProvider::factory()->approved()->listing(ServiceListingStatus::Pending, 'erp_business_applications.01')->create();
        $erp = CatalogService::query()->where('code', 'erp_business_applications.01')->value('id');
        $other = CatalogService::query()->where('code', 'automation_ot.01')->value('id');
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->postJson(route('api.v1.service-providers.services.review', [$provider, $erp]), ['decision' => 'rejected'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson(route('api.v1.service-providers.services.review', [$provider, $erp]), ['decision' => 'suspended', 'reason' => 'x'])->assertConflict();
        $this->postJson(route('api.v1.service-providers.services.review', [$provider, $other]), ['decision' => 'approved'])->assertNotFound();
        $this->postJson(route('api.v1.service-providers.services.review', [$provider, $erp]), ['decision' => 'rejected', 'reason' => 'Out of scope'])
            ->assertOk()
            ->assertJsonPath('data.service_listings.0.reason', 'Out of scope');

        expect(listingStatus($provider, 'erp_business_applications.01'))->toBe('rejected');
    });

    it('lets only IMC review listings', function () {
        $provider = ServiceProvider::factory()->listing(ServiceListingStatus::Pending, 'erp_business_applications.01')->create();
        $erp = CatalogService::query()->where('code', 'erp_business_applications.01')->value('id');

        Sanctum::actingAs(User::factory()->providerMember($provider)->create());
        $this->postJson(route('api.v1.service-providers.services.review', [$provider, $erp]), ['decision' => 'approved'])->assertForbidden();

        Sanctum::actingAs(User::factory()->providerMember()->create());
        $this->postJson(route('api.v1.service-providers.services.review', [$provider, $erp]), ['decision' => 'approved'])->assertNotFound();

        Sanctum::actingAs(User::factory()->factoryMember()->create());
        $this->postJson(route('api.v1.service-providers.services.review', [$provider, $erp]), ['decision' => 'approved'])->assertNotFound();

        expect(listingStatus($provider, 'erp_business_applications.01'))->toBe('pending');
    });

    it('never lets a promotion show a listing IMC has not approved', function () {
        $factoryMember = User::factory()->factoryMember(factoryWithEveryService('food'))->create();
        $suspended = ServiceProvider::factory()->approved()->inSectors('food')->listing(ServiceListingStatus::Suspended, 'erp_business_applications.01')->create();
        $approved = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
        foreach ([$suspended, $approved] as $provider) {
            $promotion = new ServicePromotion;
            $promotion->service_provider_id = $provider->id;
            $promotion->catalog_service_id = (int) CatalogService::query()->where('code', 'erp_business_applications.01')->value('id');
            $promotion->priority = 100;
            $promotion->starts_at = now()->subDay();
            $promotion->save();
        }

        Sanctum::actingAs($factoryMember);
        $this->getJson(route('api.v1.service-listings.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider.id', $approved->id)
            ->assertJsonPath('data.0.promotion.label', 'إعلان');
    });

    it('lets IMC filter listings by review status and sort the queue by submission', function () {
        ServiceProvider::factory()->approved()->listing(ServiceListingStatus::Pending, 'erp_business_applications.01')->create();
        ServiceProvider::factory()->approved()->offering('automation_ot.01')->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->getJson(route('api.v1.service-listings.index', ['filter' => ['listing_status' => 'pending'], 'sort' => 'newest']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.review.status', 'pending')
            ->assertJsonPath('data.0.service.code', 'erp_business_applications.01');
        $this->getJson(route('api.v1.service-providers.index', ['filter' => ['listing_status' => 'pending']]))
            ->assertOk()->assertJsonCount(1, 'data');
    });
});

describe('listing resubmission', function () {
    it('goes rejected → resubmitted (pending, not approved) → approved, keeping the same listing and its history', function () {
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->listing(ServiceListingStatus::Rejected, 'erp_business_applications.01')->create();
        $erp = CatalogService::query()->where('code', 'erp_business_applications.01')->value('id');
        $member = User::factory()->providerMember($provider)->create();
        $reviewer = User::factory()->imcAdmin()->create();
        $factoryMember = User::factory()->factoryMember(factoryWithEveryService('food'))->create();

        Sanctum::actingAs($member);
        $this->patchJson(route('api.v1.service-providers.update', $provider), ['description' => 'Corrected scope'])->assertOk();
        $this->postJson(route('api.v1.service-providers.services.resubmit', [$provider, $erp]), ['note' => 'Scope corrected'])
            ->assertOk()
            ->assertJsonPath('data.service_listings.0.status', 'pending')
            ->assertJsonPath('data.service_listings.0.reason', null);
        $this->postJson(route('api.v1.service-providers.services.resubmit', [$provider, $erp]))->assertConflict();

        Sanctum::actingAs($factoryMember);
        $this->getJson(route('api.v1.service-listings.index'))->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($reviewer);
        $this->postJson(route('api.v1.service-providers.services.review', [$provider, $erp]), ['decision' => 'approved'])->assertOk();

        Sanctum::actingAs($factoryMember);
        $this->getJson(route('api.v1.service-listings.index'))->assertOk()->assertJsonCount(1, 'data');

        expect(DB::table('catalog_service_service_provider')->where('service_provider_id', $provider->id)->count())->toBe(1)
            ->and(AuditLog::query()->where('event', AuditEvent::ServiceListingResubmitted)->sole()->metadata)
            ->toEqual(['service' => 'erp_business_applications.01', 'from' => 'rejected', 'to' => 'pending', 'note' => 'Scope corrected'])
            ->and($reviewer->notifications()->where('data->event', NotificationEvent::ServiceListingResubmitted->value)->count())->toBe(1)
            ->and($member->notifications()->where('data->event', NotificationEvent::ServiceListingResubmitted->value)->count())->toBe(1);
    });

    it('refuses to resubmit a listing that is not rejected', function (ServiceListingStatus $status) {
        $provider = ServiceProvider::factory()->approved()->listing($status, 'erp_business_applications.01')->create();
        Sanctum::actingAs(User::factory()->providerMember($provider)->create());

        $this->postJson(route('api.v1.service-providers.services.resubmit', [$provider, CatalogService::query()->where('code', 'erp_business_applications.01')->value('id')]))
            ->assertConflict();
        expect(listingStatus($provider, 'erp_business_applications.01'))->toBe($status->value);
    })->with([
        'pending' => [ServiceListingStatus::Pending],
        'approved' => [ServiceListingStatus::Approved],
        'suspended' => [ServiceListingStatus::Suspended],
    ]);

    it('lets only the provider\'s own members resubmit, and 404s a service it does not list', function () {
        $provider = ServiceProvider::factory()->listing(ServiceListingStatus::Rejected, 'erp_business_applications.01')->create();
        $erp = CatalogService::query()->where('code', 'erp_business_applications.01')->value('id');
        $other = CatalogService::query()->where('code', 'automation_ot.01')->value('id');

        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $this->postJson(route('api.v1.service-providers.services.resubmit', [$provider, $erp]))->assertForbidden();
        Sanctum::actingAs(User::factory()->providerMember()->create());
        $this->postJson(route('api.v1.service-providers.services.resubmit', [$provider, $erp]))->assertNotFound();
        Sanctum::actingAs(User::factory()->factoryMember()->create());
        $this->postJson(route('api.v1.service-providers.services.resubmit', [$provider, $erp]))->assertNotFound();
        Sanctum::actingAs(User::factory()->providerMember($provider)->create());
        $this->postJson(route('api.v1.service-providers.services.resubmit', [$provider, $other]))->assertNotFound();
        $this->postJson(route('api.v1.service-providers.services.resubmit', [$provider, $erp]), ['note' => str_repeat('x', 2001)])->assertUnprocessable();

        expect(listingStatus($provider, 'erp_business_applications.01'))->toBe('rejected');
    });

    it('never makes a suspended listing eligible for a request', function () {
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->listing(ServiceListingStatus::Suspended, 'erp_business_applications.01')->create();
        Sanctum::actingAs(User::factory()->factoryMember(factoryWithEveryService('food'))->create());

        $this->postJson(route('api.v1.service-requests.store'), erpRequest([$provider->id]))->assertUnprocessable()->assertJsonValidationErrors('provider_ids');
        $this->getJson(route('api.v1.catalog.services.index', ['filter' => ['eligible' => 1]]))->assertOk()->assertJsonCount(0, 'data');
    });
});

describe('corrections requested from a provider', function () {
    it('asks for corrections with a reason, hides the provider, and returns it to review when it asks', function () {
        $provider = ServiceProvider::factory()->inSectors('food')->offering('erp_business_applications.01')->create();
        $member = User::factory()->providerMember($provider)->create();
        $admin = User::factory()->imcAdmin()->create();

        Sanctum::actingAs($admin);
        $this->postJson(route('api.v1.service-providers.approval', $provider), ['decision' => 'changes_requested'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson(route('api.v1.service-providers.approval', $provider), ['decision' => 'changes_requested', 'reason' => 'Add the tax card'])
            ->assertOk()
            ->assertJsonPath('data.approval.status', 'changes_requested');

        Sanctum::actingAs(User::factory()->factoryMember(factoryWithEveryService('food'))->create());
        $this->getJson(route('api.v1.provider-directory.show', $provider))->assertNotFound();

        Sanctum::actingAs($member);
        $this->postJson(route('api.v1.service-providers.review-request', $provider))
            ->assertOk()
            ->assertJsonPath('data.approval.status', 'pending');

        expect($member->notifications()->where('data->event', NotificationEvent::ProviderApprovalChanged->value)->count())->toBe(1)
            ->and($admin->notifications()->where('data->event', NotificationEvent::ReviewRequested->value)->count())->toBe(1)
            ->and($provider->refresh()->approval_status)->toBe(ProviderApprovalStatus::Pending);
    });

    it('shows reviewers the missing required fields on the single profile only', function () {
        config(['jahez.providers.required_profile_fields' => ['tax_registration_number', 'services']]);
        $provider = ServiceProvider::factory()->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->getJson(route('api.v1.service-providers.show', $provider))
            ->assertOk()
            ->assertJsonPath('data.missing_required_fields', ['tax_registration_number', 'services']);
        $this->getJson(route('api.v1.service-providers.index'))
            ->assertOk()
            ->assertJsonMissingPath('data.0.missing_required_fields');
    });
});

describe('readiness results and analytics', function () {
    it('computes the analytics from the stored assessments', function () {
        $smart = Factory::factory()->create();
        storedReadinessAssessment($smart, 'a');
        $this->travel(1)->minutes();
        storedReadinessAssessment($smart, 'd');
        $basic = Factory::factory()->create();
        storedReadinessAssessment($basic, readinessChoicesForTotal(20));
        Factory::factory()->count(2)->create();

        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $data = $this->getJson(route('api.v1.readiness-analytics'))->assertOk()->json('data');

        $byCategory = collect($data['current']['by_category'])->keyBy('code');
        expect($data['factories_total'])->toBe(4)
            ->and($data['factories_assessed'])->toBe(2)
            ->and($data['factories_not_assessed'])->toBe(2)
            ->and($data['completion_rate_percent'])->toEqual(50.0)
            ->and($data['assessments_total'])->toBe(3)
            ->and($data['current']['average_score'])->toEqual(30.0)
            ->and($byCategory['smart']['factories'])->toBe(1)
            ->and($byCategory['basic']['factories'])->toBe(1)
            ->and($byCategory['b4_automation']['factories'])->toBe(0)
            ->and(collect($data['current']['by_category'])->map(fn (array $c): array => [$c['min_score'], $c['max_score']])->all())
            ->toEqual([[10, 17], [18, 25], [26, 33], [34, 40]])
            ->and($data['period']['submissions'])->toBe(3)
            ->and($data['definition'])->toEqual([
                'version' => 1, 'pillars' => 5, 'questions' => 10, 'choices' => 40,
                'score_range' => ['min' => 10, 'max' => 40], 'problems' => [],
            ]);
    });

    it('answers a null completion rate with no factories', function () {
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->getJson(route('api.v1.readiness-analytics'))
            ->assertOk()
            ->assertJsonPath('data.factories_total', 0)
            ->assertJsonPath('data.completion_rate_percent', null)
            ->assertJsonPath('data.current.average_score', null);
    });

    it('lists results across factories, and the current classification of each', function () {
        $factory = Factory::factory()->create(['name' => 'Delta Plastics']);
        storedReadinessAssessment($factory, 'a');
        $this->travel(1)->minutes();
        $latest = storedReadinessAssessment($factory, 'd');
        storedReadinessAssessment(Factory::factory()->create(['name' => 'Other']), 'b');
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->getJson(route('api.v1.readiness-assessments.index'))->assertOk()->assertJsonCount(3, 'data');
        $this->getJson(route('api.v1.readiness-assessments.index', ['filter' => ['current' => 1], 'search' => 'Delta']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $latest->id)
            ->assertJsonPath('data.0.factory.name', 'Delta Plastics')
            ->assertJsonPath('data.0.category.code', 'smart');
        $this->getJson(route('api.v1.readiness-assessments.index', ['filter' => ['category' => 'b4_automation']]))
            ->assertOk()->assertJsonCount(1, 'data');
    });

    it('is for IMC only', function () {
        Sanctum::actingAs(User::factory()->factoryMember()->create());
        $this->getJson(route('api.v1.readiness-analytics'))->assertForbidden();
        $this->getJson(route('api.v1.readiness-assessments.index'))->assertForbidden();

        Sanctum::actingAs(User::factory()->providerMember()->create());
        $this->getJson(route('api.v1.readiness-assessments.index'))->assertForbidden();
    });
});

describe('notifications of the new events', function () {
    it('tells the factory its assessment was recorded, without the score in the text', function () {
        $factory = Factory::factory()->inSectors('food')->create();
        $member = User::factory()->factoryMember($factory)->create();

        Sanctum::actingAs($member);
        $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('b'))->assertCreated();

        $notification = $member->notifications()->sole();
        expect($notification->data['event'])->toBe(NotificationEvent::ReadinessAssessmentCompleted->value)
            ->and($notification->data['body'])->not->toContain('20');
    });

    it('keeps the decision and the in-app notification when the mail transport fails, and reports the failure', function () {
        Exceptions::fake();
        Mail::extend('exploding', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('SMTP connection refused');
            }

            public function __toString(): string
            {
                return 'exploding';
            }
        });
        config(['mail.mailers.exploding' => ['transport' => 'exploding'], 'mail.default' => 'exploding']);
        $factory = Factory::factory()->withApprovalStatus(FactoryApprovalStatus::Pending)->inSectors('food')->create();
        $member = User::factory()->factoryMember($factory)->create();

        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $this->postJson(route('api.v1.factories.approval', $factory), ['decision' => 'approved'])->assertOk();

        expect($factory->refresh()->approval_status)->toBe(FactoryApprovalStatus::Approved)
            ->and($member->notifications()->where('data->event', NotificationEvent::FactoryApprovalChanged->value)->count())->toBe(1);
        Exceptions::assertReported(fn (TransportException $e): bool => str_contains($e->getMessage(), 'SMTP connection refused'));
    });

    it('tells the provider when a promotion of its listing starts and ends', function () {
        $provider = ServiceProvider::factory()->approved()->offering('erp_business_applications.01')->create();
        $member = User::factory()->providerMember($provider)->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $id = $this->postJson(route('api.v1.promotions.store'), [
            'service_provider_id' => $provider->id,
            'service' => 'erp_business_applications.01',
            'priority' => 5,
        ])->assertCreated()->json('data.id');
        $this->postJson(route('api.v1.promotions.end', $id))->assertOk();

        expect($member->notifications()->pluck('data')->pluck('event')->sort()->values()->all())
            ->toBe([NotificationEvent::PromotionEnded->value, NotificationEvent::PromotionStarted->value]);
    });
});

describe('review summary', function () {
    it('counts every review queue in one response, zeros included', function () {
        Factory::factory()->withApprovalStatus(FactoryApprovalStatus::Pending)->count(2)->create();
        Factory::factory()->create();
        ServiceProvider::factory()->withApprovalStatus(ProviderApprovalStatus::ChangesRequested)->listing(ServiceListingStatus::Pending, 'automation_ot.01')->create();
        ServiceProvider::factory()->approved()->offering('erp_business_applications.01')->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->getJson(route('api.v1.review-summary'))
            ->assertOk()
            ->assertJsonPath('data.factories', ['pending' => 2, 'approved' => 1, 'rejected' => 0, 'suspended' => 0, 'changes_requested' => 0])
            ->assertJsonPath('data.providers', ['pending' => 0, 'approved' => 1, 'rejected' => 0, 'suspended' => 0, 'changes_requested' => 1])
            ->assertJsonPath('data.listings', ['pending' => 1, 'approved' => 1, 'rejected' => 0, 'suspended' => 0])
            ->assertJsonPath('data.change_requests', ['providers' => 0, 'factories' => 0]);
    });

    it('is for IMC only', function () {
        Sanctum::actingAs(User::factory()->factoryMember()->create());
        $this->getJson(route('api.v1.review-summary'))->assertForbidden();

        Sanctum::actingAs(User::factory()->providerMember()->create());
        $this->getJson(route('api.v1.review-summary'))->assertForbidden();
    });
});

describe('backfills', function () {
    it('approves the factories and listings that existed before the review step', function () {
        $factoryId = DB::table('factories')->insertGetId(['name' => 'Old', 'created_at' => now(), 'updated_at' => now()]);
        $providerId = DB::table('service_providers')->insertGetId(['name' => 'Old provider', 'created_at' => now(), 'updated_at' => now()]);
        $serviceId = CatalogService::query()->where('code', 'automation_ot.01')->value('id');
        DB::table('catalog_service_service_provider')->insert(['service_provider_id' => $providerId, 'catalog_service_id' => $serviceId]);

        (require database_path('migrations/2026_10_04_200001_add_approval_to_factories_table.php'))->backfillApproval();
        (require database_path('migrations/2026_10_04_200002_add_review_to_catalog_service_service_provider_table.php'))->backfillReview();

        expect(DB::table('factories')->where('id', $factoryId)->value('approval_status'))->toBe('approved')
            ->and(DB::table('catalog_service_service_provider')->where('service_provider_id', $providerId)->value('status'))->toBe('approved');
    });

    it('leaves factories that IMC already decided as they are', function () {
        $factory = Factory::factory()->withApprovalStatus(FactoryApprovalStatus::Rejected)->create();

        (require database_path('migrations/2026_10_04_200001_add_approval_to_factories_table.php'))->backfillApproval();

        expect($factory->refresh()->approval_status)->toBe(FactoryApprovalStatus::Rejected);
    });
});
