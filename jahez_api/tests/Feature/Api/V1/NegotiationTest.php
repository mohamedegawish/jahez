<?php

use App\Enums\AuditEvent;
use App\Enums\ProviderApprovalStatus;
use App\Enums\ProviderRequestStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\Agreement;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\ProviderRequest;
use App\Models\ProviderRequestMessage;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * IMC suspends the provider of the thread, as the approval endpoint does.
 */
function suspendProviderOf(ProviderRequest $thread): void
{
    ServiceProvider::query()->whereKey($thread->service_provider_id)->update(['approval_status' => ProviderApprovalStatus::Suspended->value]);
}

/**
 * A valid offer payload.
 *
 * @return array<string, mixed>
 */
function offerPayload(?int $basedOnVersion = null, array $overrides = []): array
{
    return [
        'based_on_version' => $basedOnVersion,
        'scope' => 'ERP rollout for production planning and inventory.',
        'deliverables' => 'Configured ERP, data migration, training for 10 users.',
        'duration_days' => 120,
        'price' => ['amount' => '250000.00', 'currency' => 'EGP'],
        ...$overrides,
    ];
}

describe('messages', function () {
    it('lets both parties exchange messages while the negotiation is open', function () {
        ['factoryMember' => $member, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);

        Sanctum::actingAs($providerMembers[0]);
        $this->postJson(route('api.v1.provider-requests.messages.store', $threads[0]), ['body' => 'Can you share your current BOM structure?'])
            ->assertCreated()
            ->assertJsonPath('data.author_side', 'provider');

        Sanctum::actingAs($member);
        $this->postJson(route('api.v1.provider-requests.messages.store', $threads[0]), ['body' => 'Yes, attached in our next message.', 'author_side' => 'provider'])
            ->assertCreated()
            ->assertJsonPath('data.author_side', 'factory');

        expect($this->getJson(route('api.v1.provider-requests.messages.index', $threads[0]))->json('data.*.author_side'))->toBe(['provider', 'factory']);
    });

    it('refuses messages unless the negotiation is open', function (ProviderRequestStatus $status) {
        ['factoryMember' => $member, 'threads' => $threads] = marketplaceRequest(1, $status);
        Sanctum::actingAs($member);

        $this->postJson(route('api.v1.provider-requests.messages.store', $threads[0]), ['body' => 'Hello?'])->assertConflict();
        $this->assertDatabaseCount('provider_request_messages', 0);
    })->with([
        'pending' => [ProviderRequestStatus::Pending],
        'declined' => [ProviderRequestStatus::Declined],
        'agreed' => [ProviderRequestStatus::Agreed],
        'closed' => [ProviderRequestStatus::Closed],
    ]);

    it('keeps each thread private from competing providers and from IMC', function (Closure $makeUser, int $status) {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(2, ProviderRequestStatus::Accepted);
        $user = $makeUser($providerMembers);
        Sanctum::actingAs($user);

        $this->getJson(route('api.v1.provider-requests.messages.index', $threads[0]))->assertStatus($status);
        $this->getJson(route('api.v1.provider-requests.offers.index', $threads[0]))->assertStatus($status);
        $this->postJson(route('api.v1.provider-requests.messages.store', $threads[0]), ['body' => 'Hi'])->assertStatus($status);
    })->with([
        'competitor who received the same request' => [fn (array $providerMembers) => $providerMembers[1], 404],
        'provider that was not asked' => [fn () => User::factory()->providerMember()->create(), 404],
        'IMC administrator' => [fn () => User::factory()->imcAdmin()->create(), 403],
    ]);

    it('locks only the thread, not the shared request, and holds the provider approval until it commits', function (string $routeName, Closure $payload) {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        Sanctum::actingAs($providerMembers[0]);

        $reads = lockingReads(fn () => $this->postJson(route($routeName, $threads[0]), $payload())->assertCreated());

        expect($reads)->toBe(['provider_requests:update', 'service_providers:share']);
    })->with([
        'message' => ['api.v1.provider-requests.messages.store', fn () => ['body' => 'Hello']],
        'offer' => ['api.v1.provider-requests.offers.store', fn () => offerPayload()],
    ]);

    it('returns 404 to a competing provider before validating the paging input', function () {
        ['threads' => $threads] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        Sanctum::actingAs(User::factory()->providerMember()->create());

        $this->getJson(route('api.v1.provider-requests.messages.index', [$threads[0], 'per_page' => 0]))->assertNotFound();
    });

    it('accepts a message of 5000 characters and rejects a longer or empty one', function () {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        Sanctum::actingAs($providerMembers[0]);

        $this->postJson(route('api.v1.provider-requests.messages.store', $threads[0]), ['body' => str_repeat('m', 5000)])->assertCreated();
        $this->postJson(route('api.v1.provider-requests.messages.store', $threads[0]), ['body' => str_repeat('m', 5001)])->assertUnprocessable();
        $this->postJson(route('api.v1.provider-requests.messages.store', $threads[0]), ['body' => ''])->assertUnprocessable();
    });

    it('limits messages to 30 per minute per user', function () {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        Sanctum::actingAs($providerMembers[0]);
        foreach (range(1, 30) as $number) {
            $this->postJson(route('api.v1.provider-requests.messages.store', $threads[0]), ['body' => "Message {$number}"])->assertCreated();
        }

        $this->postJson(route('api.v1.provider-requests.messages.store', $threads[0]), ['body' => 'One too many'])->assertTooManyRequests();
    });

    it('never writes message content to the audit log', function () {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        Sanctum::actingAs($providerMembers[0]);

        $this->postJson(route('api.v1.provider-requests.messages.store', $threads[0]), ['body' => 'Our confidential discount'])->assertCreated();

        expect(AuditLog::query()->get()->toJson())->not->toContain('confidential');
    });
});

describe('offers', function () {
    it('creates numbered versions: the first with no base, each revision on the latest', function () {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        Sanctum::actingAs($providerMembers[0]);

        $this->postJson(route('api.v1.provider-requests.offers.store', $threads[0]), offerPayload())
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.price', ['amount' => '250000.00', 'currency' => 'EGP']);
        $this->postJson(route('api.v1.provider-requests.offers.store', $threads[0]), offerPayload(1, ['price' => ['amount' => '230000.5', 'currency' => 'EGP']]))
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.price.amount', '230000.50');

        $offers = $this->getJson(route('api.v1.provider-requests.offers.index', $threads[0]))->json('data');
        expect(array_column($offers, 'version'))->toBe([2, 1])
            ->and(array_column($offers, 'state'))->toBe(['current', 'superseded']);
    });

    it('refuses an offer based on an outdated version, which also stops duplicate retries', function (?int $basedOn) {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        offerVersion($threads[0], 1, $providerMembers[0]);
        offerVersion($threads[0], 2, $providerMembers[0]);
        Sanctum::actingAs($providerMembers[0]);

        $this->postJson(route('api.v1.provider-requests.offers.store', $threads[0]), offerPayload($basedOn))
            ->assertConflict()
            ->assertJsonPath('message', 'The latest offer is version 2; send it as based_on_version to revise it.');
        expect(Offer::query()->count())->toBe(2);
    })->with([
        'no base' => [null],
        'stale base' => [1],
        'unknown base' => [7],
    ]);

    it('rejects an offer with invalid terms with 422', function (array $overrides, string $field) {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        Sanctum::actingAs($providerMembers[0]);

        $this->postJson(route('api.v1.provider-requests.offers.store', $threads[0]), offerPayload(null, $overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with([
        'a currency other than EGP' => [['price' => ['amount' => '1000.00', 'currency' => 'USD']], 'price.currency'],
        'three decimal places' => [['price' => ['amount' => '1000.005', 'currency' => 'EGP']], 'price.amount'],
        'a negative price' => [['price' => ['amount' => '-1', 'currency' => 'EGP']], 'price.amount'],
        'a price beyond DECIMAL(14,2)' => [['price' => ['amount' => '1000000000000.00', 'currency' => 'EGP']], 'price.amount'],
        'text as price' => [['price' => ['amount' => 'a lot', 'currency' => 'EGP']], 'price.amount'],
        'a status in the price' => [['price' => ['amount' => '1', 'currency' => 'EGP', 'paid' => true]], 'price'],
        'zero days' => [['duration_days' => 0], 'duration_days'],
        'missing scope' => [['scope' => null], 'scope'],
        'missing base field' => [['based_on_version' => 'one'], 'based_on_version'],
    ]);

    it('refuses offers unless the negotiation is open', function (ProviderRequestStatus $status) {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, $status);
        Sanctum::actingAs($providerMembers[0]);

        $this->postJson(route('api.v1.provider-requests.offers.store', $threads[0]), offerPayload())->assertConflict();
    })->with([
        'pending' => [ProviderRequestStatus::Pending],
        'withdrawn' => [ProviderRequestStatus::Withdrawn],
        'closed' => [ProviderRequestStatus::Closed],
    ]);

    it('returns 403 to the factory, which negotiates by message instead of offering', function () {
        ['factoryMember' => $member, 'threads' => $threads] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        Sanctum::actingAs($member);

        $this->postJson(route('api.v1.provider-requests.offers.store', $threads[0]), offerPayload())->assertForbidden();
    });

    it('stops presenting the latest offer as current once the negotiation has ended', function (ProviderRequestStatus $status) {
        ['factoryMember' => $member, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        offerVersion($threads[0], 1, $providerMembers[0]);
        offerVersion($threads[0], 2, $providerMembers[0]);
        $threads[0]->moveTo($status);
        Sanctum::actingAs($member);

        expect($this->getJson(route('api.v1.provider-requests.offers.index', $threads[0]))->json('data.*.state'))->toBe(['lapsed', 'superseded']);
    })->with([
        'declined' => [ProviderRequestStatus::Declined],
        'withdrawn' => [ProviderRequestStatus::Withdrawn],
        'closed' => [ProviderRequestStatus::Closed],
    ]);
});

describe('while IMC has suspended the provider', function () {
    it('pauses the negotiation: no message, offer or acceptance goes through', function (Closure $act) {
        $marketplace = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        $marketplace['offer'] = offerVersion($marketplace['threads'][0], 1, $marketplace['providerMembers'][0]);
        suspendProviderOf($marketplace['threads'][0]);

        $act($marketplace)
            ->assertConflict()
            ->assertJsonPath('message', 'This provider is not approved by IMC at the moment, so this negotiation is paused.');

        expect($marketplace['threads'][0]->refresh()->status)->toBe(ProviderRequestStatus::Accepted)
            ->and($marketplace['serviceRequest']->refresh()->status)->toBe(ServiceRequestStatus::Open)
            ->and(Offer::query()->count())->toBe(1)
            ->and(ProviderRequestMessage::query()->count())->toBe(0);
    })->with([
        'the provider sends a message' => [function (array $marketplace): TestResponse {
            Sanctum::actingAs($marketplace['providerMembers'][0]);

            return test()->postJson(route('api.v1.provider-requests.messages.store', $marketplace['threads'][0]), ['body' => 'Hello']);
        }],
        'the factory sends a message' => [function (array $marketplace): TestResponse {
            Sanctum::actingAs($marketplace['factoryMember']);

            return test()->postJson(route('api.v1.provider-requests.messages.store', $marketplace['threads'][0]), ['body' => 'Hello']);
        }],
        'the provider revises its offer' => [function (array $marketplace): TestResponse {
            Sanctum::actingAs($marketplace['providerMembers'][0]);

            return test()->postJson(route('api.v1.provider-requests.offers.store', $marketplace['threads'][0]), offerPayload(1));
        }],
        'the factory accepts the offer' => [function (array $marketplace): TestResponse {
            Sanctum::actingAs($marketplace['factoryMember']);

            return test()->postJson(route('api.v1.provider-requests.offers.accept', [$marketplace['threads'][0], $marketplace['offer']]));
        }],
    ]);

    it('refuses to let the provider accept a new request', function () {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1);
        suspendProviderOf($threads[0]);
        Sanctum::actingAs($providerMembers[0]);

        $this->postJson(route('api.v1.provider-requests.accept', $threads[0]))->assertConflict();
        expect($threads[0]->refresh()->status)->toBe(ProviderRequestStatus::Pending);
    });

    it('still lets either party leave the thread', function (string $action, string $party) {
        $marketplace = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        suspendProviderOf($marketplace['threads'][0]);
        Sanctum::actingAs($party === 'provider' ? $marketplace['providerMembers'][0] : $marketplace['factoryMember']);

        $this->postJson(route("api.v1.provider-requests.{$action}", $marketplace['threads'][0]))->assertOk();
    })->with([
        'the provider declines' => ['decline', 'provider'],
        'the factory withdraws' => ['withdraw', 'factory'],
    ]);

    it('resumes the negotiation once IMC approves the provider again', function () {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        suspendProviderOf($threads[0]);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $this->postJson(route('api.v1.service-providers.approval', $threads[0]->service_provider_id), ['decision' => 'approved'])->assertOk();
        Sanctum::actingAs($providerMembers[0]);

        $this->postJson(route('api.v1.provider-requests.messages.store', $threads[0]), ['body' => 'Back again.'])->assertCreated();
    });
});

describe('accepting an offer', function () {
    it('agrees the thread, awards the request and closes the other threads', function () {
        ['factoryMember' => $member, 'serviceRequest' => $serviceRequest, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(3, ProviderRequestStatus::Accepted);
        $threads[2]->moveTo(ProviderRequestStatus::Declined);
        offerVersion($threads[0], 1, $providerMembers[0]);
        $latest = offerVersion($threads[0], 2, $providerMembers[0]);
        Sanctum::actingAs($member);

        $response = $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[0], $latest]));

        $response->assertOk()->assertJsonPath('data.state', 'accepted')->assertJsonPath('data.version', 2);
        expect($threads[0]->refresh()->status)->toBe(ProviderRequestStatus::Agreed)
            ->and($threads[0]->agreed_offer_id)->toBe($latest->id)
            ->and($serviceRequest->refresh()->status)->toBe(ServiceRequestStatus::Awarded)
            ->and($threads[1]->refresh()->status)->toBe(ProviderRequestStatus::Closed)
            ->and($threads[1]->status_reason)->toBe('request_awarded')
            ->and($threads[2]->refresh()->status)->toBe(ProviderRequestStatus::Declined);
    });

    it('refuses a superseded version with 409', function () {
        ['factoryMember' => $member, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        $first = offerVersion($threads[0], 1, $providerMembers[0]);
        offerVersion($threads[0], 2, $providerMembers[0]);
        Sanctum::actingAs($member);

        $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[0], $first]))
            ->assertConflict()
            ->assertJsonPath('message', 'Only the latest offer (version 2) can be accepted.');
        expect($threads[0]->refresh()->status)->toBe(ProviderRequestStatus::Accepted);
    });

    it('refuses a second acceptance, on the same or another thread, with 409', function () {
        ['factoryMember' => $member, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(2, ProviderRequestStatus::Accepted);
        $offerOne = offerVersion($threads[0], 1, $providerMembers[0]);
        $offerTwo = offerVersion($threads[1], 1, $providerMembers[1]);
        Sanctum::actingAs($member);
        $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[0], $offerOne]))->assertOk();

        $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[0], $offerOne]))->assertConflict();
        $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[1], $offerTwo]))->assertConflict();
        expect($threads[1]->refresh()->status)->toBe(ProviderRequestStatus::Closed);
    });

    it('returns 404 for an offer of another thread', function () {
        ['factoryMember' => $member, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(2, ProviderRequestStatus::Accepted);
        $offerOfOtherThread = offerVersion($threads[1], 1, $providerMembers[1]);
        Sanctum::actingAs($member);

        $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[0], $offerOfOtherThread]))->assertNotFound();
    });

    it('returns 403 to the provider, which cannot accept its own offer', function () {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        $offer = offerVersion($threads[0], 1, $providerMembers[0]);
        Sanctum::actingAs($providerMembers[0]);

        $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[0], $offer]))->assertForbidden();
    });

    it('locks the request row before the thread row, so concurrent accepts queue', function () {
        ['factoryMember' => $member, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        $offer = offerVersion($threads[0], 1, $providerMembers[0]);
        Sanctum::actingAs($member);

        $reads = lockingReads(fn () => $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[0], $offer]))->assertOk());

        // The agreement then share-locks the revenue-share policies it records (ADR-023).
        expect($reads)->toBe(['service_requests:update', 'provider_requests:update', 'service_providers:share', 'provider_requests:update', 'financial_policies:share']);
    });

    it('changes nothing when the acceptance cannot be audited', function () {
        ['factoryMember' => $member, 'serviceRequest' => $serviceRequest, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(2, ProviderRequestStatus::Accepted);
        $offer = offerVersion($threads[0], 1, $providerMembers[0]);
        Sanctum::actingAs($member);
        AuditLog::creating(fn (): never => throw new RuntimeException('Audit store unavailable.'));

        $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[0], $offer]))->assertServerError();

        expect($serviceRequest->refresh()->status)->toBe(ServiceRequestStatus::Open)
            ->and($threads[0]->refresh()->status)->toBe(ProviderRequestStatus::Accepted)
            ->and($threads[0]->agreed_offer_id)->toBeNull()
            ->and($threads[1]->refresh()->status)->toBe(ProviderRequestStatus::Accepted)
            ->and(Agreement::query()->count())->toBe(0);
    });

    it('records the agreement, and creates no contract, invoice or payment', function () {
        ['factoryMember' => $member, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        $offer = offerVersion($threads[0], 1, $providerMembers[0], '987654.32');
        Sanctum::actingAs($member);

        $response = $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[0], $offer]));

        $response->assertOk();
        $agreement = Agreement::query()->sole();
        expect($agreement->provider_request_id)->toBe($threads[0]->id)
            ->and($agreement->offer_id)->toBe($offer->id)
            ->and($agreement->price_amount)->toBe('987654.32')
            ->and($agreement->concluded_by_user_id)->toBe($member->id);
        foreach (['contracts', 'invoices', 'invoice_lines', 'payments'] as $table) {
            expect(Schema::hasTable($table) ? DB::table($table)->count() : 0)->toBe(0, "{$table} must stay empty");
        }
        expect($response->getContent())->not->toContain('invoice')->not->toContain('contract')->not->toContain('paid');
    });
});

describe('offer validity set by the provider', function () {
    it('stores the last day the offer may be accepted, and refuses a date in the past', function () {
        $this->travelTo('2026-10-03 09:00:00');
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        Sanctum::actingAs($providerMembers[0]);

        $this->postJson(route('api.v1.provider-requests.offers.store', $threads[0]), offerPayload(null, ['valid_until' => '2026-10-02']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['valid_until']);
        $this->postJson(route('api.v1.provider-requests.offers.store', $threads[0]), offerPayload(null, ['valid_until' => '2026-10-03']))
            ->assertCreated()
            ->assertJsonPath('data.valid_until', '2026-10-03')
            ->assertJsonPath('data.state', 'current');
    });

    it('can be accepted through its last day, and is expired afterwards', function () {
        $this->travelTo('2026-10-03 09:00:00');
        ['factoryMember' => $member, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        Sanctum::actingAs($providerMembers[0]);
        $offerId = $this->postJson(route('api.v1.provider-requests.offers.store', $threads[0]), offerPayload(null, ['valid_until' => '2026-10-10']))->json('data.id');
        Sanctum::actingAs($member);
        $this->travelTo('2026-10-11 00:00:01');

        expect($this->getJson(route('api.v1.provider-requests.offers.index', $threads[0]))->json('data.0.state'))->toBe('expired');
        $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[0], $offerId]))
            ->assertConflict()
            ->assertJsonPath('message', 'This offer was valid until 2026-10-10 and can no longer be accepted.');
        expect($threads[0]->refresh()->status)->toBe(ProviderRequestStatus::Accepted);

        $this->travelTo('2026-10-10 23:59:59');
        $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[0], $offerId]))->assertOk();
    });

    it('never expires without a date', function () {
        ['factoryMember' => $member, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        $offer = offerVersion($threads[0], 1, $providerMembers[0]);
        $this->travel(5)->years();
        Sanctum::actingAs($member);

        $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[0], $offer]))->assertOk();
    });
});

describe('award rule (OQ-38, configurable)', function () {
    it('awards the request to one provider by default, closing the others', function () {
        ['factoryMember' => $member, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(2, ProviderRequestStatus::Accepted);
        $first = offerVersion($threads[0], 1, $providerMembers[0]);
        Sanctum::actingAs($member);

        $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[0], $first]))->assertOk();

        expect($threads[1]->refresh()->status)->toBe(ProviderRequestStatus::Closed);
    });

    it('lets the factory agree with several providers when the single-award rule is off', function () {
        config(['jahez.marketplace.single_award' => false]);
        ['factoryMember' => $member, 'serviceRequest' => $serviceRequest, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(3, ProviderRequestStatus::Accepted);
        $first = offerVersion($threads[0], 1, $providerMembers[0]);
        $second = offerVersion($threads[1], 1, $providerMembers[1]);
        Sanctum::actingAs($member);

        $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[0], $first]))->assertOk();
        expect($serviceRequest->refresh()->status)->toBe(ServiceRequestStatus::Awarded)
            ->and($threads[1]->refresh()->status)->toBe(ProviderRequestStatus::Accepted);

        $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[1], $second]))->assertOk();
        expect($threads[1]->refresh()->status)->toBe(ProviderRequestStatus::Agreed)
            ->and($threads[2]->refresh()->status)->toBe(ProviderRequestStatus::Accepted)
            ->and(AuditLog::query()->where('event', AuditEvent::OfferAccepted->value)->orderBy('id')->get()->map(fn (AuditLog $entry) => $entry->metadata['closed_provider_request_ids'] ?? null)->all())->toBe([[], []]);
        $this->postJson(route('api.v1.service-requests.cancel', $serviceRequest))->assertConflict();
    });
});
