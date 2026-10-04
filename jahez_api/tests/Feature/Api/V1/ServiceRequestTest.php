<?php

use App\Enums\ProviderApprovalStatus;
use App\Enums\ProviderRequestStatus;
use App\Http\Requests\Api\V1\StoreServiceRequestRequest;
use App\Models\Factory;
use App\Models\ProviderRequest;
use App\Models\ServiceProvider;
use App\Models\ServiceRequest;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * A signed-in member of a new food-sector factory.
 */
function foodFactoryMember(): User
{
    $member = User::factory()->factoryMember(Factory::factory()->inSectors('food')->create())->create();
    Sanctum::actingAs($member);

    return $member;
}

/**
 * A valid service request payload for the given providers.
 *
 * @param  list<int>  $providerIds
 * @return array<string, mixed>
 */
function serviceRequestPayload(array $providerIds, array $overrides = []): array
{
    return [
        'service' => 'erp_business_applications.01',
        'title' => 'ERP for our dairy plant',
        'need' => 'Replace spreadsheets for production planning and inventory.',
        'requirements' => 'Arabic interface; integration with our weighbridge.',
        'provider_ids' => $providerIds,
        ...$overrides,
    ];
}

describe('store', function () {
    beforeEach(function () {
        $this->seed(ReferenceDataSeeder::class);
    });

    it('sends a request to several eligible providers, one pending thread each', function () {
        $member = foodFactoryMember();
        $providers = ServiceProvider::factory()->count(3)->approved()->inSectors('food')->offering('erp_business_applications.01')->create();

        $response = $this->postJson(route('api.v1.service-requests.store'), serviceRequestPayload($providers->pluck('id')->all()));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.service.code', 'erp_business_applications.01')
            ->assertJsonPath('data.factory.id', $member->factory_id)
            ->assertJsonCount(3, 'data.provider_requests')
            ->assertJsonPath('data.provider_requests.0.status', 'pending');
        expect(ProviderRequest::query()->where('service_request_id', $response->json('data.id'))->pluck('service_provider_id')->sort()->values()->all())
            ->toBe($providers->pluck('id')->sort()->values()->all());
    });

    it('sends a request to a single provider', function () {
        foodFactoryMember();
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();

        $this->postJson(route('api.v1.service-requests.store'), serviceRequestPayload([$provider->id]))
            ->assertCreated()
            ->assertJsonCount(1, 'data.provider_requests');
    });

    it('takes the factory from the signed-in account, never from the payload', function () {
        $member = foodFactoryMember();
        $otherFactory = Factory::factory()->create();
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();

        $response = $this->postJson(route('api.v1.service-requests.store'), serviceRequestPayload([$provider->id], [
            'factory_id' => $otherFactory->id,
            'status' => 'awarded',
            'created_by_user_id' => 1,
        ]));

        $response->assertCreated()->assertJsonPath('data.status', 'open');
        $stored = ServiceRequest::query()->findOrFail($response->json('data.id'));
        expect($stored->factory_id)->toBe($member->factory_id)->and($stored->created_by_user_id)->toBe($member->id);
    });

    it('refuses providers that are not eligible for this factory and service', function (Closure $makeProvider) {
        foodFactoryMember();
        $eligible = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();

        $response = $this->postJson(route('api.v1.service-requests.store'), serviceRequestPayload([$eligible->id, $makeProvider()->id]));

        $response->assertUnprocessable()->assertJsonValidationErrors(['provider_ids']);
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('provider_requests', 0);
    })->with([
        'pending approval' => [fn () => ServiceProvider::factory()->inSectors('food')->offering('erp_business_applications.01')->create()],
        'another sector' => [fn () => ServiceProvider::factory()->approved()->inSectors('chemical')->offering('erp_business_applications.01')->create()],
        'another service' => [fn () => ServiceProvider::factory()->approved()->inSectors('food')->offering('automation_ot.01')->create()],
    ]);

    it('checks eligibility again when saving, so a provider IMC suspends meanwhile is refused', function () {
        foodFactoryMember();
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
        app()->afterResolving(StoreServiceRequestRequest::class, function () use ($provider): void {
            ServiceProvider::query()->whereKey($provider->id)->update(['approval_status' => ProviderApprovalStatus::Suspended->value]);
        });

        $response = $this->postJson(route('api.v1.service-requests.store'), serviceRequestPayload([$provider->id]));

        $response->assertUnprocessable()->assertJsonValidationErrors(['provider_ids']);
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('provider_requests', 0);
    });

    it('share-locks the factory and the chosen providers while saving, so an approval decision waits for the request', function () {
        foodFactoryMember();
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();

        $reads = lockingReads(fn () => $this->postJson(route('api.v1.service-requests.store'), serviceRequestPayload([$provider->id]))->assertCreated());

        expect($reads)->toBe(['factories:share', 'service_providers:share']);
    });

    it('rejects an invalid request with 422', function (Closure $payload, string $field) {
        foodFactoryMember();
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();

        $this->postJson(route('api.v1.service-requests.store'), $payload($provider->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with([
        'unknown service' => [fn (int $id) => serviceRequestPayload([$id], ['service' => 'erp_business_applications.99']), 'service'],
        'no providers' => [fn (int $id) => serviceRequestPayload([]), 'provider_ids'],
        'same provider twice' => [fn (int $id) => serviceRequestPayload([$id, $id]), 'provider_ids.0'],
        'a provider that does not exist' => [fn (int $id) => serviceRequestPayload([$id, 999999]), 'provider_ids'],
        'more than 20 providers' => [fn (int $id) => serviceRequestPayload(range(1, 21)), 'provider_ids'],
        'missing need' => [fn (int $id) => serviceRequestPayload([$id], ['need' => null]), 'need'],
        'title too long' => [fn (int $id) => serviceRequestPayload([$id], ['title' => str_repeat('t', 201)]), 'title'],
    ]);

    it('returns 403 to providers and IMC administrators, because requests start at a factory', function (Closure $makeUser) {
        Sanctum::actingAs($makeUser());
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();

        $this->postJson(route('api.v1.service-requests.store'), serviceRequestPayload([$provider->id]))->assertForbidden();
    })->with([
        'provider member' => [fn () => User::factory()->providerMember()->create()],
        'IMC administrator' => [fn () => User::factory()->imcAdmin()->create()],
    ]);

    it('returns 401 without a token', function () {
        $this->postJson(route('api.v1.service-requests.store'), [])->assertUnauthorized();
    });
});

describe('index and show', function () {
    it('lists only the requests of the member\'s own factory', function () {
        ['factoryMember' => $member, 'serviceRequest' => $own] = marketplaceRequest(1);
        ServiceRequest::factory()->create();
        Sanctum::actingAs($member);

        $response = $this->getJson(route('api.v1.service-requests.index'));

        expect($response->json('data.*.id'))->toBe([$own->id]);
    });

    it('lists every request for an IMC administrator', function () {
        marketplaceRequest(1);
        ServiceRequest::factory()->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->getJson(route('api.v1.service-requests.index'))->assertOk()->assertJsonPath('meta.total', 2);
    });

    it('returns 403 to provider members, who use their provider-request inbox', function () {
        Sanctum::actingAs(User::factory()->providerMember()->create());

        $this->getJson(route('api.v1.service-requests.index'))->assertForbidden();
    });

    it('shows a provider the request and only its own thread, never its competitors', function () {
        ['serviceRequest' => $serviceRequest, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(3);
        Sanctum::actingAs($providerMembers[1]);

        $response = $this->getJson(route('api.v1.service-requests.show', $serviceRequest));

        $response->assertOk()
            ->assertJsonPath('data.need', $serviceRequest->need)
            ->assertJsonCount(1, 'data.provider_requests')
            ->assertJsonPath('data.provider_requests.0.id', $threads[1]->id);
        expect($response->getContent())->not->toContain('Provider 1')->not->toContain('Provider 3');
    });

    it('shows the requesting factory every thread, to compare the responses', function () {
        ['factoryMember' => $member, 'serviceRequest' => $serviceRequest] = marketplaceRequest(3);
        Sanctum::actingAs($member);

        $this->getJson(route('api.v1.service-requests.show', $serviceRequest))->assertOk()->assertJsonCount(3, 'data.provider_requests');
    });

    it('returns 404 to another factory and to providers that did not receive the request', function (Closure $makeOutsider) {
        ['serviceRequest' => $serviceRequest] = marketplaceRequest(1);
        Sanctum::actingAs($makeOutsider());

        $this->getJson(route('api.v1.service-requests.show', $serviceRequest))->assertNotFound();
    })->with([
        'member of another factory' => [fn () => User::factory()->factoryMember()->create()],
        'provider that was not asked' => [fn () => User::factory()->providerMember(ServiceProvider::factory()->approved()->create())->create()],
    ]);
});

describe('cancel', function () {
    it('cancels an open request and closes the threads that are not final yet', function () {
        ['factoryMember' => $member, 'serviceRequest' => $serviceRequest, 'threads' => $threads] = marketplaceRequest(3);
        $threads[1]->moveTo(ProviderRequestStatus::Accepted);
        $threads[2]->moveTo(ProviderRequestStatus::Declined, 'Fully booked');
        Sanctum::actingAs($member);

        $response = $this->postJson(route('api.v1.service-requests.cancel', $serviceRequest), ['reason' => 'Budget frozen']);

        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
        expect($threads[0]->refresh()->status)->toBe(ProviderRequestStatus::Closed)
            ->and($threads[0]->status_reason)->toBe('request_cancelled')
            ->and($threads[1]->refresh()->status)->toBe(ProviderRequestStatus::Closed)
            ->and($threads[2]->refresh()->status)->toBe(ProviderRequestStatus::Declined);
    });

    it('refuses to cancel a request twice with 409', function () {
        ['factoryMember' => $member, 'serviceRequest' => $serviceRequest] = marketplaceRequest(1);
        Sanctum::actingAs($member);
        $this->postJson(route('api.v1.service-requests.cancel', $serviceRequest))->assertOk();

        $this->postJson(route('api.v1.service-requests.cancel', $serviceRequest))
            ->assertConflict()
            ->assertJsonPath('message', 'This service request is cancelled and cannot be cancelled.');
    });

    it('returns 403 to a provider that received it and 404 to another factory', function () {
        ['serviceRequest' => $serviceRequest, 'providerMembers' => $providerMembers] = marketplaceRequest(1);

        Sanctum::actingAs($providerMembers[0]);
        $this->postJson(route('api.v1.service-requests.cancel', $serviceRequest))->assertForbidden();

        Sanctum::actingAs(User::factory()->factoryMember()->create());
        $this->postJson(route('api.v1.service-requests.cancel', $serviceRequest))->assertNotFound();

        expect($serviceRequest->refresh()->isOpen())->toBeTrue();
    });

    it('returns 404 to another factory before validating the reason', function () {
        ['serviceRequest' => $serviceRequest] = marketplaceRequest(1);
        Sanctum::actingAs(User::factory()->factoryMember()->create());

        $this->postJson(route('api.v1.service-requests.cancel', $serviceRequest), ['reason' => str_repeat('r', 2001)])->assertNotFound();
    });
});

describe('adding providers (PROPOSED, OQ-38)', function () {
    it('sends an open request to more eligible providers after every provider declined', function () {
        ['factoryMember' => $member, 'serviceRequest' => $serviceRequest, 'threads' => $threads] = marketplaceRequest(1, ProviderRequestStatus::Declined);
        $newcomer = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
        Sanctum::actingAs($member);
        expect($this->getJson(route('api.v1.service-requests.show', $serviceRequest))->json('data.active_provider_count'))->toBe(0);

        $response = $this->postJson(route('api.v1.service-requests.providers.store', $serviceRequest), ['provider_ids' => [$newcomer->id]]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.provider_requests')
            ->assertJsonPath('data.active_provider_count', 1);
        $thread = ProviderRequest::query()->where('service_provider_id', $newcomer->id)->firstOrFail();
        expect($thread->status)->toBe(ProviderRequestStatus::Pending)
            ->and($thread->transitions()->count())->toBe(1)
            ->and($threads[0]->refresh()->status)->toBe(ProviderRequestStatus::Declined);
    });

    it('refuses a provider already on the request, an ineligible one, or more than 20 in total', function (Closure $providerIds, string $message) {
        ['factoryMember' => $member, 'serviceRequest' => $serviceRequest, 'threads' => $threads] = marketplaceRequest(1, ProviderRequestStatus::Declined);
        Sanctum::actingAs($member);

        $this->postJson(route('api.v1.service-requests.providers.store', $serviceRequest), ['provider_ids' => $providerIds($threads)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.provider_ids.0', $message);
        expect(ProviderRequest::query()->where('service_request_id', $serviceRequest->id)->count())->toBe(1);
    })->with([
        'already received it' => [fn (array $threads) => [$threads[0]->service_provider_id], 'A provider in the list has already received this request.'],
        'another sector' => [fn () => [ServiceProvider::factory()->approved()->inSectors('chemical')->offering('erp_business_applications.01')->create()->id], "Each provider must be approved, offer this service and target one of your factory's sectors."],
        'not approved' => [fn () => [ServiceProvider::factory()->inSectors('food')->offering('erp_business_applications.01')->create()->id], "Each provider must be approved, offer this service and target one of your factory's sectors."],
        'a 21st provider' => [fn () => ServiceProvider::factory()->count(20)->approved()->inSectors('food')->offering('erp_business_applications.01')->create()->pluck('id')->all(), 'A request can be sent to at most 20 providers.'],
    ]);

    it('refuses to add providers to a request that is no longer open', function () {
        ['factoryMember' => $member, 'serviceRequest' => $serviceRequest] = marketplaceRequest(1);
        $newcomer = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
        Sanctum::actingAs($member);
        $this->postJson(route('api.v1.service-requests.cancel', $serviceRequest))->assertOk();

        $this->postJson(route('api.v1.service-requests.providers.store', $serviceRequest), ['provider_ids' => [$newcomer->id]])
            ->assertConflict()
            ->assertJsonPath('message', 'This service request is cancelled; providers can be added only to an open request.');
    });

    it('locks the request row, then share-locks the new providers', function () {
        ['factoryMember' => $member, 'serviceRequest' => $serviceRequest] = marketplaceRequest(1, ProviderRequestStatus::Declined);
        $newcomer = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
        Sanctum::actingAs($member);

        $reads = lockingReads(fn () => $this->postJson(route('api.v1.service-requests.providers.store', $serviceRequest), ['provider_ids' => [$newcomer->id]])->assertOk());

        expect($reads)->toBe(['service_requests:update', 'factories:share', 'service_providers:share']);
    });

    it('returns 403 to IMC and to providers on the request, and 404 to another factory, before validation', function (Closure $makeUser, int $status) {
        ['serviceRequest' => $serviceRequest, 'providerMembers' => $providerMembers] = marketplaceRequest(1);
        Sanctum::actingAs($makeUser($providerMembers));

        $this->postJson(route('api.v1.service-requests.providers.store', $serviceRequest), ['provider_ids' => []])->assertStatus($status);
    })->with([
        'IMC administrator' => [fn () => User::factory()->imcAdmin()->create(), 403],
        'provider on the request' => [fn (array $providerMembers) => $providerMembers[0], 403],
        'another factory' => [fn () => User::factory()->factoryMember()->create(), 404],
    ]);
});
