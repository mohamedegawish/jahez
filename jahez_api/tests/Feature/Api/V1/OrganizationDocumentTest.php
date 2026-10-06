<?php

use App\Enums\AuditEvent;
use App\Enums\DocumentType;
use App\Http\Controllers\Api\V1\OrganizationDocumentController;
use App\Models\AuditLog;
use App\Models\Factory;
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

describe('factory documents', function () {
    it('lets a member upload a logo and replaces the previous one, keeping it as superseded', function () {
        $factory = Factory::factory()->create();
        Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

        $first = $this->post(route('api.v1.factories.documents.store', $factory), ['type' => 'logo', 'file' => UploadedFile::fake()->image('old.png')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'logo')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.original_name', 'old.png')
            ->assertJsonMissingPath('data.path');
        $this->post(route('api.v1.factories.documents.store', $factory), ['type' => 'logo', 'file' => UploadedFile::fake()->image('new.jpg')], ['Accept' => 'application/json'])->assertCreated();

        expect(OrganizationDocument::query()->orderBy('id')->pluck('status')->map->value->all())->toBe(['superseded', 'active']);
        Storage::disk('local')->assertExists(OrganizationDocument::query()->findOrFail($first->json('data.id'))->path);

        $this->getJson(route('api.v1.factories.show', $factory))
            ->assertJsonPath('data.documents.logo.original_name', 'new.jpg')
            ->assertJsonPath('data.documents.commercial_registration', null);
    });

    it('serves a file only to the factory\'s members and IMC administrators', function () {
        $factory = Factory::factory()->create();
        $member = User::factory()->factoryMember($factory)->create();
        Sanctum::actingAs($member);
        $id = $this->post(route('api.v1.factories.documents.store', $factory), [
            'type' => 'commercial_registration',
            'file' => UploadedFile::fake()->create('cr.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->get(route('api.v1.factories.documents.show', [$factory, $id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('cr.pdf');

        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $this->get(route('api.v1.factories.documents.show', [$factory, $id]))->assertOk();

        Sanctum::actingAs(User::factory()->factoryMember()->create());
        $this->getJson(route('api.v1.factories.documents.show', [$factory, $id]))->assertNotFound();
        Sanctum::actingAs(User::factory()->providerMember(ServiceProvider::factory()->create())->create());
        $this->getJson(route('api.v1.factories.documents.show', [$factory, $id]))->assertNotFound();
    });

    it('returns 404 for a document requested under another organization', function () {
        $otherFactory = Factory::factory()->create();
        $document = OrganizationDocument::storeFor($otherFactory, DocumentType::Logo, UploadedFile::fake()->image('x.png'), null);
        $factory = Factory::factory()->create();
        Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

        $this->getJson(route('api.v1.factories.documents.show', [$factory, $document]))->assertNotFound();
    });

    it('returns 404 to outsiders before validating the upload, and 401 without a token', function () {
        $factory = Factory::factory()->create();
        $this->postJson(route('api.v1.factories.documents.store', $factory), [])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->factoryMember()->create());
        $this->postJson(route('api.v1.factories.documents.store', $factory), [])->assertNotFound();
        $this->assertDatabaseCount('organization_documents', 0);
    });

    it('keeps the documents when an unrelated profile field is edited', function () {
        $factory = Factory::factory()->inSectors('food')->create();
        Sanctum::actingAs(User::factory()->factoryMember($factory)->create());
        $this->post(route('api.v1.factories.documents.store', $factory), ['type' => 'logo', 'file' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])->assertCreated();

        $this->patchJson(route('api.v1.factories.update', $factory), ['contact_phone' => '+20 100 111 2222', 'city' => 'العاشر من رمضان'])
            ->assertOk()
            ->assertJsonPath('data.contact_phone', '+20 100 111 2222')
            ->assertJsonPath('data.city', 'العاشر من رمضان')
            ->assertJsonPath('data.documents.logo.original_name', 'logo.png');
        expect(OrganizationDocument::query()->sole()->status->value)->toBe('active');
    });

    it('rejects an unknown type, a missing file and a file of the wrong kind', function () {
        $factory = Factory::factory()->create();
        Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

        $this->post(route('api.v1.factories.documents.store', $factory), ['type' => 'passport', 'file' => UploadedFile::fake()->image('x.png')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['type']);
        $this->post(route('api.v1.factories.documents.store', $factory), ['type' => 'logo'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->post(route('api.v1.factories.documents.store', $factory), ['type' => 'logo', 'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->post(route('api.v1.factories.documents.store', $factory), ['type' => 'tax_registration', 'file' => UploadedFile::fake()->create('x.pdf', 6000, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['file']);
        expect(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('records each upload in the audit log', function () {
        $factory = Factory::factory()->create();
        $member = User::factory()->factoryMember($factory)->create();
        Sanctum::actingAs($member);

        $id = $this->post(route('api.v1.factories.documents.store', $factory), ['type' => 'logo', 'file' => UploadedFile::fake()->image('x.png')], ['Accept' => 'application/json'])->json('data.id');

        $entry = AuditLog::query()->where('event', AuditEvent::OrganizationDocumentUploaded)->sole();
        expect($entry->actor_user_id)->toBe($member->id)
            ->and($entry->subject_type)->toBe('factory')
            ->and($entry->metadata)->toEqual(['type' => 'logo', 'document_id' => $id]);
    });
});

describe('provider documents', function () {
    it('lets members of a provider IMC has not approved yet replace any document directly', function () {
        $provider = ServiceProvider::factory()->create();
        Sanctum::actingAs(User::factory()->providerMember($provider)->create());

        $this->post(route('api.v1.service-providers.documents.store', $provider), ['type' => 'tax_registration', 'file' => UploadedFile::fake()->create('tax.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active');
    });

    it('sends the legal documents of an approved provider through a change request, but not its logo', function () {
        $provider = ServiceProvider::factory()->approved()->create();
        Sanctum::actingAs(User::factory()->providerMember($provider)->create());

        $this->post(route('api.v1.service-providers.documents.store', $provider), ['type' => 'commercial_registration', 'file' => UploadedFile::fake()->create('cr.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertConflict()
            ->assertJsonPath('message', OrganizationDocumentController::LEGAL_DOCUMENT_NEEDS_REVIEW);
        $this->post(route('api.v1.service-providers.documents.store', $provider), ['type' => 'logo', 'file' => UploadedFile::fake()->image('logo.webp')], ['Accept' => 'application/json'])
            ->assertCreated();

        expect(OrganizationDocument::query()->pluck('type')->map->value->all())->toBe(['logo'])
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1)
            ->and($provider->refresh()->isApproved())->toBeTrue();
    });

    it('lets IMC administrators replace an approved provider\'s legal document directly', function () {
        $provider = ServiceProvider::factory()->approved()->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->post(route('api.v1.service-providers.documents.store', $provider), ['type' => 'commercial_registration', 'file' => UploadedFile::fake()->create('cr.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated();
    });

    it('never serves a provider\'s documents to factories or competitors', function () {
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->create();
        $document = OrganizationDocument::storeFor($provider, DocumentType::TaxRegistration, UploadedFile::fake()->create('tax.pdf', 10, 'application/pdf'), null);

        foreach ([User::factory()->factoryMember(factoryWithEveryService('food'))->create(), User::factory()->providerMember()->create()] as $outsider) {
            Sanctum::actingAs($outsider);
            $this->getJson(route('api.v1.service-providers.documents.show', [$provider, $document]))->assertNotFound();
        }
    });
});

describe('directory logos', function () {
    it('shows an approved provider\'s logo to the factories it is eligible for', function () {
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
        OrganizationDocument::storeFor($provider, DocumentType::Logo, UploadedFile::fake()->image('logo.png'), null);
        Sanctum::actingAs(User::factory()->factoryMember(factoryWithEveryService('food'))->create());

        $this->getJson(route('api.v1.provider-directory.show', $provider))->assertJsonPath('data.has_logo', true);
        $this->get(route('api.v1.provider-directory.logo', $provider))->assertOk()->assertHeader('Content-Type', 'image/png');
    });

    it('returns 404 for a provider the factory cannot see, or one without a logo', function () {
        $hidden = ServiceProvider::factory()->inSectors('food')->offering('erp_business_applications.01')->create();
        OrganizationDocument::storeFor($hidden, DocumentType::Logo, UploadedFile::fake()->image('logo.png'), null);
        $withoutLogo = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
        Sanctum::actingAs(User::factory()->factoryMember(factoryWithEveryService('food'))->create());

        $this->getJson(route('api.v1.provider-directory.logo', $hidden))->assertNotFound();
        $this->getJson(route('api.v1.provider-directory.logo', $withoutLogo))->assertNotFound();
        $this->getJson(route('api.v1.provider-directory.show', $withoutLogo))->assertJsonPath('data.has_logo', false);
    });
});
