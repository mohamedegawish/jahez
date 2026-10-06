<?php

use App\Models\AuditLog;
use App\Models\Factory;
use App\Models\FactoryAssessment;
use App\Models\MaturityTier;
use App\Models\Sector;
use App\Models\ServiceRequest;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Arr;
use Laravel\Sanctum\Sanctum;

describe('index', function () {
    it('lists factories with their sectors, paginated, for an IMC administrator', function () {
        $this->seed(ReferenceDataSeeder::class);
        $factories = Factory::factory()->count(2)->inSectors('food')->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->getJson(route('api.v1.factories.index', ['per_page' => 1]));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $factories[0]->id)
            ->assertJsonPath('data.0.sectors.0.code', 'food')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1);
    });

    it('returns 403 to factory and provider members', function (User $member) {
        Sanctum::actingAs($member);

        $response = $this->getJson(route('api.v1.factories.index'));

        $response->assertForbidden()->assertJsonPath('code', 'forbidden');
    })->with([
        'factory member' => [fn (): User => User::factory()->factoryMember()->create()],
        'provider member' => [fn (): User => User::factory()->providerMember()->create()],
    ]);

    it('returns 401 without a token', function () {
        $this->getJson(route('api.v1.factories.index'))->assertUnauthorized();
    });

    it('rejects a page size above 100 with 422', function () {
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->getJson(route('api.v1.factories.index', ['per_page' => 101]));

        $response->assertUnprocessable()->assertJsonPath('errors.per_page.0', 'The per page field must not be greater than 100.');
    });

    it('lists card summaries without legal or contact details, which stay on the details endpoint', function () {
        $this->seed(ReferenceDataSeeder::class);
        $factory = Factory::factory()->inSectors('food')->create([
            'legal_name' => 'Legal Name LLC',
            'contact_name' => 'Contact Person',
            'contact_email' => 'contact@example.test',
            'contact_phone' => '+201000000000',
            'address' => '12 Industrial Zone',
            'commercial_registration_number' => 'CR-123456',
            'tax_registration_number' => 'TX-654321',
            'governorate' => 'الجيزة',
            'city' => '6 أكتوبر',
        ]);
        ServiceRequest::factory()->count(2)->create(['factory_id' => $factory->id]);
        storedReadinessAssessment($factory, 'c');
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $card = $this->getJson(route('api.v1.factories.index'))->assertOk()->json('data.0');

        expect(array_keys($card))->toEqualCanonicalizing([
            'id', 'name', 'size', 'governorate', 'city', 'sectors', 'logo', 'onboarding',
            'current_readiness', 'readiness_level', 'approval', 'profile_completion', 'service_requests_count', 'created_at', 'updated_at',
        ])
            ->and($card['profile_completion'])->toBe(['filled' => 9, 'total' => 10, 'missing' => ['logo']])
            ->and($card['service_requests_count'])->toBe(2)
            ->and($card['logo'])->toBeNull()
            ->and($card['current_readiness']['total_score'])->toBe(30)
            ->and($card['readiness_level'])->toBe(['code' => 'advanced', 'name_ar' => $card['current_readiness']['category']['name_ar'], 'name_en' => 'Advanced', 'unlocked_by' => 'assessment', 'unlocked_at' => null])
            ->and(json_encode($card))->not->toContain('CR-123456')
            ->and(json_encode($card))->not->toContain('contact@example.test')
            ->and(json_encode($card))->not->toContain('12 Industrial Zone');
        $this->getJson(route('api.v1.factories.show', $factory))
            ->assertOk()
            ->assertJsonPath('data.commercial_registration_number', 'CR-123456')
            ->assertJsonPath('data.contact_email', 'contact@example.test');
    });
});

describe('store', function () {
    it('creates a factory with sectors for an IMC administrator', function () {
        $this->seed(ReferenceDataSeeder::class);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->postJson(route('api.v1.factories.store'), ['name' => 'Delta Foods', 'sectors' => ['food', 'chemical']]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Delta Foods')
            ->assertJsonPath('data.sectors.*.code', ['food', 'chemical']);
        $factory = Factory::query()->where('name', 'Delta Foods')->firstOrFail();
        expect($factory->sectors()->pluck('code')->all())->toBe(['food', 'chemical']);
    });

    it('rejects a missing name with 422', function () {
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->postJson(route('api.v1.factories.store'), []);

        $response->assertUnprocessable()->assertJsonPath('errors.name.0', 'The name field is required.');
    });

    it('rejects an unknown sector code with 422', function () {
        $this->seed(ReferenceDataSeeder::class);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->postJson(route('api.v1.factories.store'), ['name' => 'Delta Foods', 'sectors' => ['textiles']]);

        $response->assertUnprocessable()->assertJsonPath('errors', ['sectors.0' => ['The selected sectors.0 is invalid.']]);
        $this->assertDatabaseMissing('factories', ['name' => 'Delta Foods']);
    });

    it('rejects more sectors than exist without checking each one', function () {
        $this->seed(ReferenceDataSeeder::class);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $sectorCodes = Sector::query()->pluck('code')->all();

        $response = $this->postJson(route('api.v1.factories.store'), ['name' => 'Delta Foods', 'sectors' => [...$sectorCodes, 'food']]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors', ['sectors' => ['The sectors field must not have more than '.count($sectorCodes).' items.']]);
    });

    it('returns 403 to a factory member and creates nothing', function () {
        Sanctum::actingAs(User::factory()->factoryMember()->create());

        $response = $this->postJson(route('api.v1.factories.store'), ['name' => 'Self-made Factory']);

        $response->assertForbidden();
        $this->assertDatabaseMissing('factories', ['name' => 'Self-made Factory']);
    });
});

describe('show', function () {
    it('shows any factory to an IMC administrator', function () {
        $factory = Factory::factory()->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->getJson(route('api.v1.factories.show', $factory))->assertOk()->assertJsonPath('data.id', $factory->id);
    });

    it('shows a member their own factory', function () {
        $factory = Factory::factory()->create();
        Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

        $this->getJson(route('api.v1.factories.show', $factory))->assertOk()->assertJsonPath('data.id', $factory->id);
    });

    it('returns 404 for another factory, indistinguishable from one that does not exist', function (User $outsider) {
        $otherFactory = Factory::factory()->create();
        Sanctum::actingAs($outsider);

        $otherFactoryResponse = $this->getJson(route('api.v1.factories.show', $otherFactory));
        $missingFactoryResponse = $this->getJson(route('api.v1.factories.show', 999999));

        $otherFactoryResponse->assertNotFound();
        $missingFactoryResponse->assertNotFound();
        expect(Arr::except($otherFactoryResponse->json(), 'request_id'))
            ->toBe(Arr::except($missingFactoryResponse->json(), 'request_id'));
    })->with([
        'member of another factory' => [fn (): User => User::factory()->factoryMember()->create()],
        'provider member' => [fn (): User => User::factory()->providerMember()->create()],
    ]);
});

describe('update', function () {
    it('renames a factory and replaces its sectors for an IMC administrator', function () {
        $this->seed(ReferenceDataSeeder::class);
        $factory = Factory::factory()->inSectors('food')->create(['name' => 'Old Name']);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->patchJson(route('api.v1.factories.update', $factory), ['name' => 'New Name', 'sectors' => ['chemical']]);

        $response->assertOk()->assertJsonPath('data.name', 'New Name')->assertJsonPath('data.sectors.*.code', ['chemical']);
        expect($factory->refresh()->name)->toBe('New Name')
            ->and($factory->sectors()->pluck('code')->all())->toBe(['chemical']);
    });

    it('keeps the sectors when the request does not mention them', function () {
        $this->seed(ReferenceDataSeeder::class);
        $factory = Factory::factory()->inSectors('food')->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->patchJson(route('api.v1.factories.update', $factory), ['name' => 'New Name'])->assertOk();

        expect($factory->sectors()->pluck('code')->all())->toBe(['food']);
    });

    it('lets a member update their own factory name and sectors', function () {
        $this->seed(ReferenceDataSeeder::class);
        $factory = Factory::factory()->inSectors('food')->create(['name' => 'Old Name']);
        Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

        $response = $this->patchJson(route('api.v1.factories.update', $factory), ['name' => 'New Name', 'sectors' => ['chemical']]);

        $response->assertOk()->assertJsonPath('data.name', 'New Name')->assertJsonPath('data.sectors.*.code', ['chemical']);
        expect($factory->refresh()->name)->toBe('New Name');
    });

    it('ignores any field a member may not set', function () {
        $factory = Factory::factory()->create(['name' => 'Old Name']);
        $createdAt = $factory->created_at?->toIso8601ZuluString();
        Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

        $this->patchJson(route('api.v1.factories.update', $factory), ['id' => 999, 'created_at' => '2020-01-01T00:00:00Z', 'assessments' => [['maturity_tier' => 'smart_dx']]])
            ->assertOk()
            ->assertJsonPath('data.id', $factory->id)
            ->assertJsonPath('data.created_at', $createdAt);
        $this->assertDatabaseCount('factory_assessments', 0);
    });

    it('returns 404 to a member of another factory, before validating the payload', function () {
        $otherFactory = Factory::factory()->create(['name' => 'Old Name']);
        Sanctum::actingAs(User::factory()->factoryMember()->create());

        $response = $this->patchJson(route('api.v1.factories.update', $otherFactory), ['name' => '']);

        $response->assertNotFound();
        expect($otherFactory->refresh()->name)->toBe('Old Name');
    });
});

describe('size (OQ-04 interim: declared, from a configurable list)', function () {
    it('stores the size an IMC administrator declares, and changes it', function () {
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $id = $this->postJson(route('api.v1.factories.store'), ['name' => 'Delta Foods', 'size' => 'medium'])
            ->assertCreated()
            ->assertJsonPath('data.size', 'medium')
            ->json('data.id');

        $this->patchJson(route('api.v1.factories.update', $id), ['size' => 'large'])->assertOk()->assertJsonPath('data.size', 'large');
        $this->patchJson(route('api.v1.factories.update', $id), ['size' => null])->assertOk()->assertJsonPath('data.size', null);
    });

    it('rejects a size outside the configured list', function (string $size) {
        config(['jahez.factories.sizes' => ['small' => 'الصغيرة', 'medium' => 'المتوسطة']]);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->postJson(route('api.v1.factories.store'), ['name' => 'Delta Foods', 'size' => $size])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['size']);
    })->with(['large', 'micro', 'Medium']);

    it('ignores a size sent by a factory member', function () {
        $factory = Factory::factory()->create(['size' => 'small']);
        Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

        $this->patchJson(route('api.v1.factories.update', $factory), ['size' => 'large'])->assertOk()->assertJsonPath('data.size', 'small');
    });
});

describe('admin filters', function () {
    beforeEach(function () {
        $this->seed(ReferenceDataSeeder::class);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());
    });

    it('filters by sector and size, and searches names literally', function (array $query, array $expectedNames) {
        Factory::factory()->inSectors('food')->create(['name' => 'Delta 100% Foods', 'size' => 'small']);
        Factory::factory()->inSectors('chemical')->create(['name' => 'Delta 1000 Chemicals', 'size' => 'large']);
        Factory::factory()->inSectors('food', 'chemical')->create(['name' => 'Nile Mixed', 'size' => 'large']);

        $names = $this->getJson(route('api.v1.factories.index', $query))->assertOk()->json('data.*.name');

        expect($names)->toBe($expectedNames);
    })->with([
        'sector' => [['filter' => ['sector' => 'chemical']], ['Delta 1000 Chemicals', 'Nile Mixed']],
        'size' => [['filter' => ['size' => 'small']], ['Delta 100% Foods']],
        'sector and size' => [['filter' => ['sector' => 'food', 'size' => 'large']], ['Nile Mixed']],
        'search with a % sign' => [['search' => '100%'], ['Delta 100% Foods']],
        'search' => [['search' => 'delta'], ['Delta 100% Foods', 'Delta 1000 Chemicals']],
    ]);

    it('rejects an unknown filter or value with 422', function (array $query, string $field) {
        $this->getJson(route('api.v1.factories.index', $query))->assertUnprocessable()->assertJsonValidationErrors([$field]);
    })->with([
        'unknown key' => [['filter' => ['name' => 'x']], 'filter'],
        'unknown sector' => [['filter' => ['sector' => 'textiles']], 'filter.sector'],
        'unknown size' => [['filter' => ['size' => 'huge']], 'filter.size'],
    ]);

    it('returns 403 to members before validating the filters', function () {
        Sanctum::actingAs(User::factory()->factoryMember()->create());

        $this->getJson(route('api.v1.factories.index', ['per_page' => 0]))->assertForbidden();
    });
});

describe('current readiness classification', function () {
    it('shows the latest completed readiness assessment, or null before the first', function () {
        $this->seed(ReferenceDataSeeder::class);
        $factory = Factory::factory()->create();
        Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

        $this->getJson(route('api.v1.factories.show', $factory))->assertOk()->assertJsonPath('data.current_readiness', null);

        $this->travelTo('2026-09-01 08:00:00');
        storedReadinessAssessment($factory, 'd');
        $this->travelTo('2026-10-01 08:00:00');
        $current = storedReadinessAssessment($factory, 'b');

        $response = $this->getJson(route('api.v1.factories.show', $factory));

        // A factory member sees its category and level, never its score (ADR-026).
        $response->assertJsonPath('data.current_readiness', [
            'assessment_id' => $current->id,
            'questionnaire_version' => 1,
            'category' => ['code' => 'basic', 'name_en' => 'Basic', 'name_ar' => 'مبتدئ / رقمنة أساسية'],
            'completed_at' => '2026-10-01T08:00:00Z',
        ])->assertJsonPath('data.readiness_level', [
            'code' => 'basic',
            'name_ar' => 'مبتدئ / رقمنة أساسية',
            'name_en' => 'Basic',
            'unlocked_by' => 'assessment',
            'unlocked_at' => null,
        ]);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        expect($this->getJson(route('api.v1.factories.index'))->json('data.0.current_readiness.assessment_id'))->toBe($current->id)
            ->and($this->getJson(route('api.v1.factories.show', $factory))->json('data.current_readiness.total_score'))->toBe(20);
    });

    it('does not turn a legacy manual classification into a readiness classification', function () {
        $this->seed(ReferenceDataSeeder::class);
        $factory = Factory::factory()->create();
        $legacy = new FactoryAssessment;
        $legacy->factory_id = $factory->id;
        $legacy->maturity_tier_id = MaturityTier::query()->where('code', 'advanced_dx')->value('id');
        $legacy->justification = 'Field visit';
        $legacy->assessed_on = now();
        $legacy->recorded_by_user_id = User::factory()->imcAdmin()->create()->id;
        $legacy->save();
        Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

        $this->getJson(route('api.v1.factories.show', $factory))->assertOk()->assertJsonPath('data.current_readiness', null);
    });
});

describe('registration details and onboarding (ADR-019)', function () {
    it('lets a member fill the registration details, recording only which fields changed', function () {
        $factory = Factory::factory()->create();
        Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

        $this->patchJson(route('api.v1.factories.update', $factory), [
            'legal_name' => 'شركة المصنع ش.م.م',
            'contact_name' => 'Hala',
            'contact_email' => 'hala@factory.example',
            'contact_phone' => '+20 100 222 3333',
            'governorate' => 'الإسكندرية',
            'commercial_registration_number' => '٧٧٨٨٩٩',
        ])
            ->assertOk()
            ->assertJsonPath('data.legal_name', 'شركة المصنع ش.م.م')
            ->assertJsonPath('data.contact_email', 'hala@factory.example')
            ->assertJsonPath('data.commercial_registration_number', '٧٧٨٨٩٩');

        expect(AuditLog::query()->where('event', 'factory.updated')->sole()->metadata)->toEqual([
            'fields' => ['commercial_registration_number', 'contact_email', 'contact_name', 'contact_phone', 'governorate', 'legal_name'],
        ]);
    });

    it('rejects malformed contact details and registration numbers', function () {
        $factory = Factory::factory()->create();
        Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

        $this->patchJson(route('api.v1.factories.update', $factory), ['contact_email' => 'nope', 'contact_phone' => 'call me', 'tax_registration_number' => str_repeat('1', 51)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contact_email', 'contact_phone', 'tax_registration_number']);
    });

    it('shows the onboarding steps: missing profile fields and whether the assessment is done', function () {
        $this->seed(ReferenceDataSeeder::class);
        $factory = Factory::factory()->create();
        Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

        $this->getJson(route('api.v1.factories.show', $factory))
            ->assertJsonPath('data.onboarding', ['missing_profile_fields' => ['sectors'], 'profile_complete' => false, 'readiness_status' => 'not_started']);

        $this->patchJson(route('api.v1.factories.update', $factory), ['sectors' => ['food']])
            ->assertJsonPath('data.onboarding.profile_complete', true);
        storedReadinessAssessment($factory, 'a');

        $this->getJson(route('api.v1.factories.show', $factory))
            ->assertJsonPath('data.onboarding', ['missing_profile_fields' => [], 'profile_complete' => true, 'readiness_status' => 'completed']);
    });

    it('lists the configured onboarding fields the factory has not filled', function () {
        config(['jahez.factories.required_profile_fields' => ['contact_phone', 'governorate', 'not_a_field']]);
        $factory = Factory::factory()->create(['governorate' => 'القاهرة']);
        Sanctum::actingAs(User::factory()->factoryMember($factory)->create());

        $this->getJson(route('api.v1.factories.show', $factory))->assertJsonPath('data.onboarding.missing_profile_fields', ['contact_phone']);
    });
});
