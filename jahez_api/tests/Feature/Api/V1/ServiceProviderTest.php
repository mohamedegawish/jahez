<?php

use App\Enums\ProviderApprovalStatus;
use App\Models\Sector;
use App\Models\ServiceProvider;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Laravel\Sanctum\Sanctum;

describe('index', function () {
    it('lists service providers for an IMC administrator', function () {
        $serviceProvider = ServiceProvider::factory()->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->getJson(route('api.v1.service-providers.index'));

        $response->assertOk()->assertJsonPath('data.0.id', $serviceProvider->id)->assertJsonPath('meta.total', 1);
    });

    it('returns 403 to a provider member', function () {
        Sanctum::actingAs(User::factory()->providerMember()->create());

        $this->getJson(route('api.v1.service-providers.index'))->assertForbidden();
    });
});

describe('store', function () {
    it('creates a service provider with target sectors for an IMC administrator', function () {
        $this->seed(ReferenceDataSeeder::class);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->postJson(route('api.v1.service-providers.store'), ['name' => 'Nile Systems', 'sectors' => ['medical_pharmaceutical']]);

        $response->assertCreated()->assertJsonPath('data.sectors.*.code', ['medical_pharmaceutical']);
        $this->assertDatabaseHas('service_providers', ['name' => 'Nile Systems']);
    });

    it('creates a provider with every workbook field and its services, pending IMC approval', function () {
        $this->seed(ReferenceDataSeeder::class);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->postJson(route('api.v1.service-providers.store'), [
            'name' => 'Nile Systems',
            'representative_name' => 'Hoda Kamel',
            'job_title' => 'Managing Director',
            'email' => 'hoda@nile.example.test',
            'phone' => '+20 (2) 1234-5678',
            'website' => 'https://nile.example.test',
            'dx_experience_years' => 12,
            'sectors' => ['food', 'chemical'],
            'services' => ['erp_business_applications.01', 'ai_data_analytics.02'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.representative_name', 'Hoda Kamel')
            ->assertJsonPath('data.job_title', 'Managing Director')
            ->assertJsonPath('data.email', 'hoda@nile.example.test')
            ->assertJsonPath('data.phone', '+20 (2) 1234-5678')
            ->assertJsonPath('data.website', 'https://nile.example.test')
            ->assertJsonPath('data.dx_experience_years', 12)
            ->assertJsonPath('data.sectors.*.code', ['food', 'chemical'])
            ->assertJsonPath('data.services.*.code', ['erp_business_applications.01', 'ai_data_analytics.02'])
            ->assertJsonPath('data.services.0.category.code', 'erp_business_applications')
            ->assertJsonPath('data.approval', ['status' => 'pending', 'reason' => null, 'changed_at' => null]);
    });

    it('needs only the company name, because the workbook marks no field as required', function () {
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->postJson(route('api.v1.service-providers.store'), ['name' => 'Minimal Provider'])->assertCreated();
    });

    it('rejects workbook fields in the wrong format with 422', function (string $field, mixed $value, ?string $errorKey = null) {
        $this->seed(ReferenceDataSeeder::class);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->postJson(route('api.v1.service-providers.store'), ['name' => 'Nile Systems', $field => $value]);

        $response->assertUnprocessable()->assertJsonValidationErrors([$errorKey ?? $field]);
    })->with([
        'email without a domain' => ['email', 'hoda@'],
        'website without a scheme' => ['website', 'nile.example.test'],
        'website with a script scheme' => ['website', 'javascript:alert(1)'],
        'phone with letters' => ['phone', '+20 CALL ME'],
        'phone too short' => ['phone', '12345'],
        'negative years' => ['dx_experience_years', -1],
        'years above 100' => ['dx_experience_years', 101],
        'fractional years' => ['dx_experience_years', 2.5],
        'unknown service' => ['services', ['erp_business_applications.99'], 'services.0'],
        'services not a list' => ['services', 'erp_business_applications.01'],
    ]);

    it('accepts the boundary values of the experience years', function (int $years) {
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->postJson(route('api.v1.service-providers.store'), ['name' => 'Nile Systems', 'dx_experience_years' => $years])->assertCreated();
    })->with([0, 100]);

    it('returns 403 to a provider member and creates nothing', function () {
        Sanctum::actingAs(User::factory()->providerMember()->create());

        $response = $this->postJson(route('api.v1.service-providers.store'), ['name' => 'Self-made Provider']);

        $response->assertForbidden();
        $this->assertDatabaseMissing('service_providers', ['name' => 'Self-made Provider']);
    });
});

describe('show', function () {
    it('shows a member their own service provider', function () {
        $serviceProvider = ServiceProvider::factory()->create();
        Sanctum::actingAs(User::factory()->providerMember($serviceProvider)->create());

        $this->getJson(route('api.v1.service-providers.show', $serviceProvider))->assertOk()->assertJsonPath('data.id', $serviceProvider->id);
    });

    it('returns 404 for another service provider', function (User $outsider) {
        $otherServiceProvider = ServiceProvider::factory()->create();
        Sanctum::actingAs($outsider);

        $response = $this->getJson(route('api.v1.service-providers.show', $otherServiceProvider));

        $response->assertNotFound()->assertJsonPath('message', 'Resource not found.');
    })->with([
        'member of another provider' => [fn (): User => User::factory()->providerMember()->create()],
        'factory member' => [fn (): User => User::factory()->factoryMember()->create()],
    ]);
});

describe('update', function () {
    it('renames a service provider for an IMC administrator', function () {
        $serviceProvider = ServiceProvider::factory()->create(['name' => 'Old Name']);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->patchJson(route('api.v1.service-providers.update', $serviceProvider), ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        expect($serviceProvider->refresh()->name)->toBe('New Name');
    });

    it('rejects more sectors than exist without checking each one', function () {
        $this->seed(ReferenceDataSeeder::class);
        $serviceProvider = ServiceProvider::factory()->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $sectorCodes = Sector::query()->pluck('code')->all();

        $response = $this->patchJson(route('api.v1.service-providers.update', $serviceProvider), ['sectors' => [...$sectorCodes, 'food']]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors', ['sectors' => ['The sectors field must not have more than '.count($sectorCodes).' items.']]);
    });

    it('lets a member update their own provider profile and services, but never its approval', function () {
        $this->seed(ReferenceDataSeeder::class);
        $serviceProvider = ServiceProvider::factory()->create(['name' => 'Old Name']);
        Sanctum::actingAs(User::factory()->providerMember($serviceProvider)->create());

        $response = $this->patchJson(route('api.v1.service-providers.update', $serviceProvider), [
            'name' => 'New Name',
            'website' => 'https://new.example.test',
            'services' => ['automation_ot.01'],
            'approval_status' => 'approved',
            'approval_reason' => 'Self-approved',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.services.*.code', ['automation_ot.01'])
            ->assertJsonPath('data.approval.status', 'pending');
        expect($serviceProvider->refresh()->approval_status->value)->toBe('pending')
            ->and($serviceProvider->approval_reason)->toBeNull();
    });

    it('keeps an approved provider approved when its members edit the profile', function () {
        $serviceProvider = ServiceProvider::factory()->approved()->create();
        Sanctum::actingAs(User::factory()->providerMember($serviceProvider)->create());

        $this->patchJson(route('api.v1.service-providers.update', $serviceProvider), ['phone' => '+20 2 1234 5678'])->assertOk();

        expect($serviceProvider->refresh()->approval_status->value)->toBe('approved');
    });

    it('returns 404 to a member of another provider', function () {
        $otherServiceProvider = ServiceProvider::factory()->create(['name' => 'Old Name']);
        Sanctum::actingAs(User::factory()->providerMember()->create());

        $this->patchJson(route('api.v1.service-providers.update', $otherServiceProvider), ['name' => 'New Name'])->assertNotFound();

        expect($otherServiceProvider->refresh()->name)->toBe('Old Name');
    });
});

describe('admin filters', function () {
    beforeEach(function () {
        $this->seed(ReferenceDataSeeder::class);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());
    });

    it('lists the review queue and filters by sector, service and name', function (array $query, array $expectedNames) {
        ServiceProvider::factory()->inSectors('food')->offering('erp_business_applications.01')->create(['name' => 'Pending ERP']);
        ServiceProvider::factory()->approved()->inSectors('chemical')->offering('automation_ot.01')->create(['name' => 'Approved OT']);
        ServiceProvider::factory()->withApprovalStatus(ProviderApprovalStatus::Rejected)->inSectors('food')->create(['name' => 'Rejected_Co']);

        $names = $this->getJson(route('api.v1.service-providers.index', $query))->assertOk()->json('data.*.name');

        expect($names)->toBe($expectedNames);
    })->with([
        'review queue' => [['filter' => ['approval_status' => 'pending']], ['Pending ERP']],
        'sector' => [['filter' => ['sector' => 'food']], ['Pending ERP', 'Rejected_Co']],
        'service' => [['filter' => ['service' => 'automation_ot.01']], ['Approved OT']],
        'search with an underscore' => [['search' => 'd_C'], ['Rejected_Co']],
    ]);

    it('rejects an unknown filter or value with 422', function (array $query, string $field) {
        $this->getJson(route('api.v1.service-providers.index', $query))->assertUnprocessable()->assertJsonValidationErrors([$field]);
    })->with([
        'unknown key' => [['filter' => ['email' => 'x']], 'filter'],
        'unknown status' => [['filter' => ['approval_status' => 'banned']], 'filter.approval_status'],
        'unknown service' => [['filter' => ['service' => 'erp_business_applications.99']], 'filter.service'],
    ]);

    it('returns 403 to members before validating the filters', function () {
        Sanctum::actingAs(User::factory()->providerMember()->create());

        $this->getJson(route('api.v1.service-providers.index', ['filter' => ['approval_status' => 'banned']]))->assertForbidden();
    });
});
