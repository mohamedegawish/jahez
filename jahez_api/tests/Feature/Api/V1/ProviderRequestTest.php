<?php

use App\Enums\ProviderRequestStatus;
use App\Models\Factory;
use App\Models\ProviderRequest;
use App\Models\ServiceProvider;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Laravel\Sanctum\Sanctum;

describe('inbox', function () {
    it('lists to a provider only the requests sent to it', function () {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(2);
        ProviderRequest::factory()->create();
        Sanctum::actingAs($providerMembers[0]);

        $response = $this->getJson(route('api.v1.provider-requests.index'));

        expect($response->json('data.*.id'))->toBe([$threads[0]->id])
            ->and($response->json('data.0.service_request.title'))->not->toBeNull();
    });

    it('lists to a factory the threads of its own requests', function () {
        ['factoryMember' => $member, 'threads' => $threads] = marketplaceRequest(2);
        ProviderRequest::factory()->create();
        Sanctum::actingAs($member);

        $response = $this->getJson(route('api.v1.provider-requests.index'));

        expect($response->json('data.*.id'))->toBe([$threads[1]->id, $threads[0]->id]);
    });

    it('filters by status', function () {
        ['factoryMember' => $member, 'threads' => $threads] = marketplaceRequest(2);
        $threads[1]->moveTo(ProviderRequestStatus::Accepted);
        Sanctum::actingAs($member);

        expect($this->getJson(route('api.v1.provider-requests.index', ['filter' => ['status' => 'accepted']]))->json('data.*.id'))->toBe([$threads[1]->id]);
        $this->getJson(route('api.v1.provider-requests.index', ['filter' => ['status' => 'paid']]))->assertUnprocessable();
    });

    it('returns 404 for a thread of another provider or factory', function (Closure $makeOutsider) {
        ['threads' => $threads] = marketplaceRequest(1);
        Sanctum::actingAs($makeOutsider());

        $this->getJson(route('api.v1.provider-requests.show', $threads[0]))->assertNotFound();
    })->with([
        'competing provider' => [fn () => User::factory()->providerMember()->create()],
        'another factory' => [fn () => User::factory()->factoryMember()->create()],
    ]);
});

describe('provider answer', function () {
    it('lets the provider accept a pending request, which opens the negotiation', function () {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(2);
        Sanctum::actingAs($providerMembers[0]);

        $response = $this->postJson(route('api.v1.provider-requests.accept', $threads[0]));

        $response->assertOk()->assertJsonPath('data.status', 'accepted');
        expect($threads[1]->refresh()->status)->toBe(ProviderRequestStatus::Pending);
    });

    it('lets the provider decline, with a reason, before or during the negotiation', function (ProviderRequestStatus $from) {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, $from);
        Sanctum::actingAs($providerMembers[0]);

        $this->postJson(route('api.v1.provider-requests.decline', $threads[0]), ['reason' => 'No capacity this quarter'])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.status_reason', 'No capacity this quarter');
    })->with([
        'pending' => [ProviderRequestStatus::Pending],
        'negotiating' => [ProviderRequestStatus::Accepted],
    ]);

    it('refuses a transition the workflow does not allow with 409', function (ProviderRequestStatus $from, string $action) {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, $from);
        Sanctum::actingAs($providerMembers[0]);

        $this->postJson(route("api.v1.provider-requests.{$action}", $threads[0]))
            ->assertConflict()
            ->assertJsonPath('code', 'conflict');
        expect($threads[0]->refresh()->status)->toBe($from);
    })->with([
        'accept twice' => [ProviderRequestStatus::Accepted, 'accept'],
        'accept after declining' => [ProviderRequestStatus::Declined, 'accept'],
        'accept after withdrawal' => [ProviderRequestStatus::Withdrawn, 'accept'],
        'accept a closed thread' => [ProviderRequestStatus::Closed, 'accept'],
        'decline twice' => [ProviderRequestStatus::Declined, 'decline'],
        'decline after agreement' => [ProviderRequestStatus::Agreed, 'decline'],
    ]);

    it('returns 403 to the requesting factory, which cannot answer for the provider', function () {
        ['factoryMember' => $member, 'threads' => $threads] = marketplaceRequest(1);
        Sanctum::actingAs($member);

        $this->postJson(route('api.v1.provider-requests.accept', $threads[0]))->assertForbidden();
        expect($threads[0]->refresh()->status)->toBe(ProviderRequestStatus::Pending);
    });

    it('returns 404 to a competing provider trying to answer', function () {
        ['threads' => $threads] = marketplaceRequest(1);
        Sanctum::actingAs(User::factory()->providerMember()->create());

        $this->postJson(route('api.v1.provider-requests.accept', $threads[0]))->assertNotFound();
    });
});

describe('factory withdrawal', function () {
    it('lets the factory withdraw the request from one provider', function (ProviderRequestStatus $from) {
        ['factoryMember' => $member, 'threads' => $threads] = marketplaceRequest(2, $from);
        Sanctum::actingAs($member);

        $this->postJson(route('api.v1.provider-requests.withdraw', $threads[0]), ['reason' => 'Chose a local provider'])
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn');
        expect($threads[1]->refresh()->status)->toBe($from);
    })->with([
        'pending' => [ProviderRequestStatus::Pending],
        'negotiating' => [ProviderRequestStatus::Accepted],
    ]);

    it('refuses to withdraw a thread that is already final with 409', function () {
        ['factoryMember' => $member, 'threads' => $threads] = marketplaceRequest(1, ProviderRequestStatus::Declined);
        Sanctum::actingAs($member);

        $this->postJson(route('api.v1.provider-requests.withdraw', $threads[0]))->assertConflict();
    });

    it('returns 403 to the provider, which declines instead of withdrawing', function () {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1);
        Sanctum::actingAs($providerMembers[0]);

        $this->postJson(route('api.v1.provider-requests.withdraw', $threads[0]))->assertForbidden();
    });
});

it('rejects a reason longer than 2000 characters', function () {
    ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1);
    Sanctum::actingAs($providerMembers[0]);

    $this->postJson(route('api.v1.provider-requests.decline', $threads[0]), ['reason' => str_repeat('r', 2001)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);
});

it('returns 404 to a competing provider before validating the reason', function (string $action) {
    ['threads' => $threads] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
    Sanctum::actingAs(User::factory()->providerMember()->create());

    $this->postJson(route("api.v1.provider-requests.{$action}", $threads[0]), ['reason' => str_repeat('r', 2001)])->assertNotFound();
})->with(['decline', 'withdraw']);

it('lets IMC administrators see a thread and its status, but never act on it', function () {
    ['threads' => $threads] = marketplaceRequest(1);
    Sanctum::actingAs(User::factory()->imcAdmin()->create());

    $this->getJson(route('api.v1.provider-requests.show', $threads[0]))->assertOk()->assertJsonPath('data.status', 'pending');
    $this->postJson(route('api.v1.provider-requests.accept', $threads[0]))->assertForbidden();
    $this->postJson(route('api.v1.provider-requests.withdraw', $threads[0]))->assertForbidden();
});

describe('history', function () {
    it('records every status change with its actor, side and reason, oldest first', function () {
        ['factoryMember' => $member, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1);
        Sanctum::actingAs($providerMembers[0]);
        $this->postJson(route('api.v1.provider-requests.accept', $threads[0]))->assertOk();
        $this->postJson(route('api.v1.provider-requests.decline', $threads[0]), ['reason' => 'Scope too large'])->assertOk();
        Sanctum::actingAs($member);

        $history = $this->getJson(route('api.v1.provider-requests.history', $threads[0]))->assertOk()->json('data');

        expect(array_map(fn (array $change): array => [$change['from'], $change['to'], $change['reason'], $change['actor']['side'] ?? null], $history))->toBe([
            ['pending', 'accepted', null, 'provider'],
            ['accepted', 'declined', 'Scope too large', 'provider'],
        ])->and($history[0]['actor']['id'])->toBe($providerMembers[0]->id);
    });

    it('starts with the creation, and shows a closure caused by the factory', function () {
        $this->seed(ReferenceDataSeeder::class);
        $member = User::factory()->factoryMember(Factory::factory()->inSectors('food')->create())->create();
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
        Sanctum::actingAs($member);
        $requestId = $this->postJson(route('api.v1.service-requests.store'), [
            'service' => 'erp_business_applications.01', 'title' => 'ERP', 'need' => 'Planning', 'provider_ids' => [$provider->id],
        ])->json('data.id');
        $this->postJson(route('api.v1.service-requests.cancel', $requestId), ['reason' => 'Budget frozen'])->assertOk();
        $thread = ProviderRequest::query()->where('service_request_id', $requestId)->firstOrFail();

        $history = $this->getJson(route('api.v1.provider-requests.history', $thread))->json('data');

        expect(array_map(fn (array $change): array => [$change['from'], $change['to'], $change['reason'], $change['actor']['side']], $history))->toBe([
            [null, 'pending', null, 'factory'],
            ['pending', 'closed', 'request_cancelled', 'factory'],
        ]);
    });

    it('is visible to the two parties and IMC, and 404 to a competitor', function (Closure $makeUser, int $status) {
        ['factoryMember' => $member, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(2);
        Sanctum::actingAs($makeUser($member, $providerMembers));

        $this->getJson(route('api.v1.provider-requests.history', $threads[0]))->assertStatus($status);
    })->with([
        'the factory' => [fn (User $member) => $member, 200],
        'the provider' => [fn (User $member, array $providerMembers) => $providerMembers[0], 200],
        'IMC administrator' => [fn () => User::factory()->imcAdmin()->create(), 200],
        'competitor on the same request' => [fn (User $member, array $providerMembers) => $providerMembers[1], 404],
        'another factory' => [fn () => User::factory()->factoryMember()->create(), 404],
    ]);
});
