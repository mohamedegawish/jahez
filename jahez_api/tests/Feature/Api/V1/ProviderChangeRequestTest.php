<?php

use App\Enums\AuditEvent;
use App\Enums\DocumentType;
use App\Http\Requests\Api\V1\StoreProviderChangeRequestRequest;
use App\Http\Requests\Api\V1\UpdateServiceProviderRequest;
use App\Models\AuditLog;
use App\Models\OrganizationDocument;
use App\Models\ServiceProvider;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    Storage::fake('local');
});

/**
 * An approved provider with verified legal information and a current registry document.
 *
 * @return array{ServiceProvider, User}
 */
function verifiedProvider(): array
{
    $provider = ServiceProvider::factory()->approved()->create([
        'legal_name' => 'Delta Automation S.A.E.',
        'commercial_registration_number' => '445566',
        'tax_registration_number' => '112-233-445',
    ]);
    OrganizationDocument::storeFor($provider, DocumentType::CommercialRegistration, UploadedFile::fake()->create('old-cr.pdf', 10, 'application/pdf'), null);

    return [$provider, User::factory()->providerMember($provider)->create()];
}

describe('profile edits', function () {
    it('lets members of a provider not yet approved edit the legal fields directly', function () {
        $provider = ServiceProvider::factory()->create();
        Sanctum::actingAs(User::factory()->providerMember($provider)->create());

        $this->patchJson(route('api.v1.service-providers.update', $provider), ['legal_name' => 'New Legal Name', 'tax_registration_number' => '999'])
            ->assertOk()
            ->assertJsonPath('data.legal_name', 'New Legal Name')
            ->assertJsonPath('data.legal_information_verified', false);
    });

    it('refuses a changed legal field from a member of an approved provider, and accepts the stored value', function () {
        [$provider, $member] = verifiedProvider();
        Sanctum::actingAs($member);

        $this->patchJson(route('api.v1.service-providers.update', $provider), ['legal_name' => 'Somebody Else Ltd', 'description' => 'Changed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['legal_name' => UpdateServiceProviderRequest::LEGAL_FIELD_NEEDS_REVIEW]);
        expect($provider->refresh()->legal_name)->toBe('Delta Automation S.A.E.')
            ->and($provider->description)->toBeNull();

        $this->patchJson(route('api.v1.service-providers.update', $provider), ['legal_name' => 'Delta Automation S.A.E.', 'commercial_registration_number' => '445566', 'description' => 'Changed', 'city' => 'Giza'])
            ->assertOk()
            ->assertJsonPath('data.description', 'Changed')
            ->assertJsonPath('data.approval.status', 'approved')
            ->assertJsonPath('data.legal_information_verified', true);
    });

    it('lets IMC administrators correct an approved provider\'s legal fields directly', function () {
        [$provider] = verifiedProvider();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->patchJson(route('api.v1.service-providers.update', $provider), ['legal_name' => 'Delta Automation Systems S.A.E.'])
            ->assertOk()
            ->assertJsonPath('data.legal_name', 'Delta Automation Systems S.A.E.');
    });
});

describe('change requests', function () {
    it('records the requested values and documents without changing the provider', function () {
        [$provider, $member] = verifiedProvider();
        Sanctum::actingAs($member);

        $response = $this->post(route('api.v1.service-providers.change-requests.store', $provider), [
            'legal_name' => 'Delta Automation Systems S.A.E.',
            'commercial_registration_number' => '445566',
            'commercial_registration_document' => UploadedFile::fake()->create('new-cr.pdf', 50, 'application/pdf'),
            'note' => 'The company was renamed.',
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.changes', ['legal_name' => 'Delta Automation Systems S.A.E.'])
            ->assertJsonPath('data.documents.0.status', 'pending_review')
            ->assertJsonPath('data.service_provider.legal_name', 'Delta Automation S.A.E.');
        expect($provider->refresh()->legal_name)->toBe('Delta Automation S.A.E.')
            ->and($provider->activeDocuments()->sole()->original_name)->toBe('old-cr.pdf');

        $this->getJson(route('api.v1.service-providers.show', $provider))
            ->assertJsonPath('data.open_change_request.id', $response->json('data.id'))
            ->assertJsonPath('data.documents.commercial_registration.original_name', 'old-cr.pdf');
    });

    it('allows one pending request at a time', function () {
        [$provider, $member] = verifiedProvider();
        Sanctum::actingAs($member);

        $this->postJson(route('api.v1.service-providers.change-requests.store', $provider), ['tax_registration_number' => '1'])->assertCreated();
        $this->postJson(route('api.v1.service-providers.change-requests.store', $provider), ['tax_registration_number' => '2'])->assertConflict();
    });

    it('refuses a request that changes nothing, and one for a provider IMC has not approved', function () {
        [$provider, $member] = verifiedProvider();
        Sanctum::actingAs($member);
        $this->postJson(route('api.v1.service-providers.change-requests.store', $provider), ['legal_name' => 'Delta Automation S.A.E.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['changes' => StoreProviderChangeRequestRequest::NOTHING_TO_CHANGE]);

        $pending = ServiceProvider::factory()->create();
        Sanctum::actingAs(User::factory()->providerMember($pending)->create());
        $this->postJson(route('api.v1.service-providers.change-requests.store', $pending), ['legal_name' => 'X'])->assertConflict();
    });

    it('applies an approved request and keeps the replaced document as superseded', function () {
        [$provider, $member] = verifiedProvider();
        Sanctum::actingAs($member);
        $id = $this->post(route('api.v1.service-providers.change-requests.store', $provider), [
            'legal_name' => 'Delta Automation Systems S.A.E.',
            'commercial_registration_document' => UploadedFile::fake()->create('new-cr.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->json('data.id');

        $admin = User::factory()->imcAdmin()->create();
        Sanctum::actingAs($admin);
        $this->getJson(route('api.v1.provider-change-requests.index'))->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson(route('api.v1.service-providers.change-requests.approve', [$provider, $id]))
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewed_by.id', $admin->id);

        $provider->refresh();
        expect($provider->legal_name)->toBe('Delta Automation Systems S.A.E.')
            ->and($provider->isApproved())->toBeTrue()
            ->and($provider->activeDocuments()->sole()->original_name)->toBe('new-cr.pdf')
            ->and(OrganizationDocument::query()->where('original_name', 'old-cr.pdf')->sole()->status->value)->toBe('superseded')
            ->and($provider->openChangeRequest()->exists())->toBeFalse();
        $this->getJson(route('api.v1.provider-change-requests.index'))->assertJsonCount(0, 'data');
        $this->postJson(route('api.v1.service-providers.change-requests.approve', [$provider, $id]))->assertConflict();

        $entry = AuditLog::query()->where('event', AuditEvent::ProviderChangeRequestApproved)->sole();
        expect($entry->metadata)->toEqual(['change_request_id' => $id, 'fields' => ['legal_name'], 'documents' => ['commercial_registration']]);
    });

    it('rejects a request with a reason and changes nothing', function () {
        [$provider, $member] = verifiedProvider();
        Sanctum::actingAs($member);
        $id = $this->post(route('api.v1.service-providers.change-requests.store', $provider), [
            'legal_name' => 'Wrong Name',
            'commercial_registration_document' => UploadedFile::fake()->create('new-cr.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->json('data.id');

        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $this->postJson(route('api.v1.service-providers.change-requests.reject', [$provider, $id]))->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postJson(route('api.v1.service-providers.change-requests.reject', [$provider, $id]), ['reason' => 'The registry extract does not match.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.review_reason', 'The registry extract does not match.')
            ->assertJsonPath('data.documents.0.status', 'rejected');

        expect($provider->refresh()->legal_name)->toBe('Delta Automation S.A.E.')
            ->and($provider->activeDocuments()->sole()->original_name)->toBe('old-cr.pdf');
    });

    it('lets the member cancel a pending request and submit another', function () {
        [$provider, $member] = verifiedProvider();
        Sanctum::actingAs($member);
        $id = $this->postJson(route('api.v1.service-providers.change-requests.store', $provider), ['legal_name' => 'Typo Ltd'])->json('data.id');

        $this->postJson(route('api.v1.service-providers.change-requests.cancel', [$provider, $id]))->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson(route('api.v1.service-providers.change-requests.store', $provider), ['legal_name' => 'Right Ltd'])->assertCreated();
        $this->getJson(route('api.v1.service-providers.change-requests.index', $provider))->assertOk()->assertJsonCount(2, 'data');
    });

    it('lets only IMC decide, only the provider\'s members submit, and hides requests from others', function () {
        [$provider, $member] = verifiedProvider();
        Sanctum::actingAs($member);
        $id = $this->postJson(route('api.v1.service-providers.change-requests.store', $provider), ['legal_name' => 'X Ltd'])->json('data.id');

        $this->postJson(route('api.v1.service-providers.change-requests.approve', [$provider, $id]))->assertForbidden();
        $this->getJson(route('api.v1.provider-change-requests.index'))->assertForbidden();

        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $this->postJson(route('api.v1.service-providers.change-requests.store', $provider), ['legal_name' => 'Y Ltd'])->assertForbidden();

        Sanctum::actingAs(User::factory()->providerMember()->create());
        $this->getJson(route('api.v1.service-providers.change-requests.index', $provider))->assertNotFound();
        $this->postJson(route('api.v1.service-providers.change-requests.cancel', [$provider, $id]))->assertNotFound();
        $this->postJson(route('api.v1.service-providers.change-requests.approve', [$provider, $id]))->assertNotFound();
    });

    it('returns 404 for a change request addressed through another provider', function () {
        [$provider, $member] = verifiedProvider();
        Sanctum::actingAs($member);
        $id = $this->postJson(route('api.v1.service-providers.change-requests.store', $provider), ['legal_name' => 'X Ltd'])->json('data.id');
        $other = ServiceProvider::factory()->approved()->create();

        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $this->postJson(route('api.v1.service-providers.change-requests.approve', [$other, $id]))->assertNotFound();
    });
});
