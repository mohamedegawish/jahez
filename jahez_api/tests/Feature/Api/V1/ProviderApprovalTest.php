<?php

use App\Enums\ProviderApprovalStatus;
use App\Models\ServiceProvider;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Laravel\Sanctum\Sanctum;

it('applies every transition the approval workflow allows', function (ProviderApprovalStatus $from, string $decision) {
    $serviceProvider = ServiceProvider::factory()->withApprovalStatus($from)->create();
    Sanctum::actingAs(User::factory()->imcAdmin()->create());
    $this->travelTo('2026-10-03 10:00:00');

    $response = $this->postJson(route('api.v1.service-providers.approval', $serviceProvider), ['decision' => $decision, 'reason' => 'Reviewed by IMC']);

    $response->assertOk()
        ->assertJsonPath('data.approval.status', $decision)
        ->assertJsonPath('data.approval.reason', 'Reviewed by IMC')
        ->assertJsonPath('data.approval.changed_at', '2026-10-03T10:00:00Z');
    expect($serviceProvider->refresh()->approval_status->value)->toBe($decision);
})->with([
    'pending to approved' => [ProviderApprovalStatus::Pending, 'approved'],
    'pending to rejected' => [ProviderApprovalStatus::Pending, 'rejected'],
    'approved to suspended' => [ProviderApprovalStatus::Approved, 'suspended'],
    'suspended to approved' => [ProviderApprovalStatus::Suspended, 'approved'],
    'rejected to approved' => [ProviderApprovalStatus::Rejected, 'approved'],
]);

it('refuses a transition the workflow does not allow with 409 and changes nothing', function (ProviderApprovalStatus $from, string $decision) {
    $serviceProvider = ServiceProvider::factory()->withApprovalStatus($from)->create();
    Sanctum::actingAs(User::factory()->imcAdmin()->create());

    $response = $this->postJson(route('api.v1.service-providers.approval', $serviceProvider), ['decision' => $decision, 'reason' => 'Any reason']);

    $response->assertConflict()
        ->assertJsonPath('code', 'conflict')
        ->assertJsonPath('message', "A provider that is {$from->value} cannot become {$decision}.");
    expect($serviceProvider->refresh()->approval_status)->toBe($from);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    'pending to suspended' => [ProviderApprovalStatus::Pending, 'suspended'],
    'approved again' => [ProviderApprovalStatus::Approved, 'approved'],
    'approved to rejected' => [ProviderApprovalStatus::Approved, 'rejected'],
    'rejected again' => [ProviderApprovalStatus::Rejected, 'rejected'],
    'suspended again' => [ProviderApprovalStatus::Suspended, 'suspended'],
]);

it('requires a reason to reject or suspend', function (ProviderApprovalStatus $from, string $decision) {
    $serviceProvider = ServiceProvider::factory()->withApprovalStatus($from)->create();
    Sanctum::actingAs(User::factory()->imcAdmin()->create());

    $response = $this->postJson(route('api.v1.service-providers.approval', $serviceProvider), ['decision' => $decision]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['reason']);
})->with([
    'reject' => [ProviderApprovalStatus::Pending, 'rejected'],
    'suspend' => [ProviderApprovalStatus::Approved, 'suspended'],
]);

it('approves without a reason', function () {
    $serviceProvider = ServiceProvider::factory()->create();
    Sanctum::actingAs(User::factory()->imcAdmin()->create());

    $this->postJson(route('api.v1.service-providers.approval', $serviceProvider), ['decision' => 'approved'])
        ->assertOk()
        ->assertJsonPath('data.approval.reason', null);
});

it('rejects an unknown or pending decision with 422', function (mixed $decision) {
    $serviceProvider = ServiceProvider::factory()->create();
    Sanctum::actingAs(User::factory()->imcAdmin()->create());

    $this->postJson(route('api.v1.service-providers.approval', $serviceProvider), ['decision' => $decision, 'reason' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['decision']);
})->with(['pending', 'verified', null]);

it('returns 403 to the provider itself and 404 to anyone else outside IMC', function (string $actor, int $status) {
    $serviceProvider = ServiceProvider::factory()->create();
    Sanctum::actingAs(match ($actor) {
        'own member' => User::factory()->providerMember($serviceProvider)->create(),
        'member of another provider' => User::factory()->providerMember()->create(),
        'factory member' => User::factory()->factoryMember()->create(),
    });

    $this->postJson(route('api.v1.service-providers.approval', $serviceProvider), ['decision' => 'approved'])->assertStatus($status);

    expect($serviceProvider->refresh()->approval_status)->toBe(ProviderApprovalStatus::Pending);
})->with([
    ['own member', 403],
    ['member of another provider', 404],
    ['factory member', 404],
]);

it('returns 401 without a token', function () {
    $this->postJson(route('api.v1.service-providers.approval', ServiceProvider::factory()->create()), ['decision' => 'approved'])->assertUnauthorized();
});

describe('required profile fields (OQ-36, configurable)', function () {
    it('approves an incomplete profile while no field is required', function () {
        $serviceProvider = ServiceProvider::factory()->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->postJson(route('api.v1.service-providers.approval', $serviceProvider), ['decision' => 'approved'])->assertOk();
    });

    it('refuses to approve a provider missing a configured required field', function () {
        config(['jahez.providers.required_profile_fields' => ['email', 'sectors', 'not_a_field']]);
        $serviceProvider = ServiceProvider::factory()->create(['phone' => '+20 2 1234 5678']);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->postJson(route('api.v1.service-providers.approval', $serviceProvider), ['decision' => 'approved']);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.decision.0', 'The provider profile is missing required fields: email, sectors.');
        expect($serviceProvider->refresh()->approval_status)->toBe(ProviderApprovalStatus::Pending);
    });

    it('approves once the required fields are filled, and still rejects without them', function () {
        $this->seed(ReferenceDataSeeder::class);
        config(['jahez.providers.required_profile_fields' => ['email', 'sectors']]);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $complete = ServiceProvider::factory()->inSectors('food')->create(['email' => 'info@provider.example']);
        $incomplete = ServiceProvider::factory()->create();

        $this->postJson(route('api.v1.service-providers.approval', $complete), ['decision' => 'approved'])->assertOk();
        $this->postJson(route('api.v1.service-providers.approval', $incomplete), ['decision' => 'rejected', 'reason' => 'Incomplete'])->assertOk();
    });
});

describe('review request after a rejection (PROPOSED)', function () {
    it('lets the rejected provider ask IMC for a new review, which returns it to pending', function () {
        $serviceProvider = ServiceProvider::factory()->withApprovalStatus(ProviderApprovalStatus::Rejected)->create(['approval_reason' => 'Missing references']);
        Sanctum::actingAs(User::factory()->providerMember($serviceProvider)->create());

        $response = $this->postJson(route('api.v1.service-providers.review-request', $serviceProvider), ['note' => 'References added to the website.']);

        $response->assertOk()
            ->assertJsonPath('data.approval.status', 'pending')
            ->assertJsonPath('data.approval.reason', null);
        expect($serviceProvider->refresh()->approval_status)->toBe(ProviderApprovalStatus::Pending);
    });

    it('refuses a review request unless the provider was rejected or asked for corrections', function (ProviderApprovalStatus $status) {
        $serviceProvider = ServiceProvider::factory()->withApprovalStatus($status)->create();
        Sanctum::actingAs(User::factory()->providerMember($serviceProvider)->create());

        $this->postJson(route('api.v1.service-providers.review-request', $serviceProvider))
            ->assertConflict()
            ->assertJsonPath('message', "A provider that is {$status->value} cannot ask for a new review; only a rejected provider or one asked for corrections can.");
        expect($serviceProvider->refresh()->approval_status)->toBe($status);
    })->with([
        'pending' => [ProviderApprovalStatus::Pending],
        'approved' => [ProviderApprovalStatus::Approved],
        'suspended' => [ProviderApprovalStatus::Suspended],
    ]);

    it('asks for the configured required fields first', function () {
        config(['jahez.providers.required_profile_fields' => ['website']]);
        $serviceProvider = ServiceProvider::factory()->withApprovalStatus(ProviderApprovalStatus::Rejected)->create();
        Sanctum::actingAs(User::factory()->providerMember($serviceProvider)->create());

        $this->postJson(route('api.v1.service-providers.review-request', $serviceProvider))
            ->assertUnprocessable()
            ->assertJsonPath('errors.profile.0', 'Complete these profile fields before asking for a review: website.');
    });

    it('returns 403 to IMC, which decides through the approval action, and 404 to anyone else before validation', function (Closure $makeUser, int $status) {
        $serviceProvider = ServiceProvider::factory()->withApprovalStatus(ProviderApprovalStatus::Rejected)->create();
        Sanctum::actingAs($makeUser());

        $this->postJson(route('api.v1.service-providers.review-request', $serviceProvider), ['note' => str_repeat('n', 2001)])->assertStatus($status);
        expect($serviceProvider->refresh()->approval_status)->toBe(ProviderApprovalStatus::Rejected);
    })->with([
        'IMC administrator' => [fn () => User::factory()->imcAdmin()->create(), 403],
        'another provider' => [fn () => User::factory()->providerMember()->create(), 404],
        'factory member' => [fn () => User::factory()->factoryMember()->create(), 404],
    ]);

    it('rejects a note longer than 2000 characters', function () {
        $serviceProvider = ServiceProvider::factory()->withApprovalStatus(ProviderApprovalStatus::Rejected)->create();
        Sanctum::actingAs(User::factory()->providerMember($serviceProvider)->create());

        $this->postJson(route('api.v1.service-providers.review-request', $serviceProvider), ['note' => str_repeat('n', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note']);
    });
});
