<?php

use App\Enums\ProviderApprovalStatus;
use App\Models\Factory;
use App\Models\ServiceProvider;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

/**
 * Signs in as a member of a new factory in the given sectors.
 */
function actingAsMemberOfFactoryIn(string ...$sectorCodes): User
{
    $factory = Factory::factory()->inSectors(...$sectorCodes)->create();
    // Readiness-based eligibility (ADR-025) is tested in ServiceEligibilityTest.
    everyServiceAvailable($factory);
    $member = User::factory()->factoryMember($factory)->create();
    Sanctum::actingAs($member);

    return $member;
}

it('lists approved providers that target one of the factory sectors', function () {
    $eligible = ServiceProvider::factory()->approved()->inSectors('food', 'chemical')->offering('erp_business_applications.01')->create(['name' => 'Eligible Foods']);
    ServiceProvider::factory()->approved()->inSectors('engineering_metal')->offering('erp_business_applications.01')->create(['name' => 'Other Sector']);
    actingAsMemberOfFactoryIn('food');

    $response = $this->getJson(route('api.v1.provider-directory'));

    $response->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $eligible->id);
});

it('hides providers that IMC has not approved', function (ProviderApprovalStatus $status) {
    ServiceProvider::factory()->withApprovalStatus($status)->inSectors('food')->offering('erp_business_applications.01')->create();
    actingAsMemberOfFactoryIn('food');

    $this->getJson(route('api.v1.provider-directory'))->assertOk()->assertJsonPath('meta.total', 0);
})->with([
    'pending' => [ProviderApprovalStatus::Pending],
    'rejected' => [ProviderApprovalStatus::Rejected],
    'suspended' => [ProviderApprovalStatus::Suspended],
]);

it('shows the public profile only, without contact details, legal information or approval state', function () {
    ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create([
        'name' => 'Delta Digital',
        'legal_name' => 'Delta Digital Holding S.A.E.',
        'commercial_registration_number' => '778899',
        'tax_registration_number' => '445-566-778',
        'representative_name' => 'Hoda Kamel',
        'job_title' => 'Managing Director',
        'email' => 'hoda@delta.example.test',
        'phone' => '+20 2 1234 5678',
        'website' => 'https://delta.example.test',
        'dx_experience_years' => 9,
    ]);
    actingAsMemberOfFactoryIn('food');

    $provider = $this->getJson(route('api.v1.provider-directory'))->json('data.0');

    expect(array_keys($provider))->toBe(['id', 'name', 'description', 'governorate', 'city', 'website', 'has_logo', 'dx_experience_years', 'sectors', 'services'])
        ->and($provider['services'][0]['code'])->toBe('erp_business_applications.01')
        ->and(json_encode($provider))->not->toContain('Hoda')->not->toContain('1234')->not->toContain('hoda@')
        ->not->toContain('Holding')->not->toContain('778899')->not->toContain('445-566');
});

it('lists nothing for a factory with no sectors', function () {
    ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
    actingAsMemberOfFactoryIn();

    $this->getJson(route('api.v1.provider-directory'))->assertOk()->assertJsonPath('meta.total', 0);
});

it('filters by service, category and sector', function (array $filter, string $expectedName) {
    ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create(['name' => 'ERP House']);
    ServiceProvider::factory()->approved()->inSectors('chemical')->offering('ot_ics_cybersecurity.02')->create(['name' => 'Secure Plant']);
    actingAsMemberOfFactoryIn('food', 'chemical');

    $response = $this->getJson(route('api.v1.provider-directory', ['filter' => $filter]));

    expect($response->json('data.*.name'))->toBe([$expectedName]);
})->with([
    'service' => [['service' => 'ot_ics_cybersecurity.02'], 'Secure Plant'],
    'category' => [['category' => 'erp_business_applications'], 'ERP House'],
    'sector' => [['sector' => 'chemical'], 'Secure Plant'],
]);

it('rejects filters and sorts outside the allow-list with 422', function (array $query, string $field) {
    actingAsMemberOfFactoryIn('food');

    $this->getJson(route('api.v1.provider-directory', $query))->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'a sector the factory is not in' => [['filter' => ['sector' => 'chemical']], 'filter.sector'],
    'an unknown filter key' => [['filter' => ['approval_status' => 'pending']], 'filter'],
    'an unknown service' => [['filter' => ['service' => 'erp_business_applications.99']], 'filter.service'],
    'an unknown sort' => [['sort' => 'email'], 'sort'],
    'SQL in the sort' => [['sort' => 'name; DROP TABLE users'], 'sort'],
    'page size above 100' => [['per_page' => 101], 'per_page'],
]);

it('sorts by the allow-listed keys', function (string $sort, array $expectedNames) {
    ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create(['name' => 'Beta', 'dx_experience_years' => 3]);
    ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create(['name' => 'Alpha', 'dx_experience_years' => 10]);
    actingAsMemberOfFactoryIn('food');

    expect($this->getJson(route('api.v1.provider-directory', ['sort' => $sort]))->json('data.*.name'))->toBe($expectedNames);
})->with([
    'name' => ['name', ['Alpha', 'Beta']],
    'name descending' => ['-name', ['Beta', 'Alpha']],
    'years' => ['dx_experience_years', ['Beta', 'Alpha']],
    'years descending' => ['-dx_experience_years', ['Alpha', 'Beta']],
]);

it('searches names literally, treating % and _ as plain characters', function (string $term, array $expectedNames) {
    foreach (['100% Digital', '1000 Systems', 'Data_Works', 'DataXWorks'] as $name) {
        ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create(['name' => $name]);
    }
    actingAsMemberOfFactoryIn('food');

    expect($this->getJson(route('api.v1.provider-directory', ['search' => $term]))->json('data.*.name'))->toBe($expectedNames);
})->with([
    'percent sign' => ['100%', ['100% Digital']],
    'underscore' => ['a_W', ['Data_Works']],
]);

it('shows IMC administrators every approved provider, in any sector', function () {
    ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
    ServiceProvider::factory()->approved()->inSectors('engineering_metal')->offering('erp_business_applications.01')->create();
    ServiceProvider::factory()->create();
    Sanctum::actingAs(User::factory()->imcAdmin()->create());

    $this->getJson(route('api.v1.provider-directory'))->assertOk()->assertJsonPath('meta.total', 2);
});

it('returns 403 to provider members, who may not browse their competitors', function () {
    Sanctum::actingAs(User::factory()->providerMember()->create());

    $this->getJson(route('api.v1.provider-directory'))->assertForbidden();
});

it('returns 401 without a token', function () {
    $this->getJson(route('api.v1.provider-directory'))->assertUnauthorized();
});

it('runs the same number of queries however many providers are listed', function () {
    $member = actingAsMemberOfFactoryIn('food');
    $queriesFor = function (int $providerCount) use ($member): int {
        ServiceProvider::factory()->count($providerCount)->approved()->inSectors('food')->offering('erp_business_applications.01', 'automation_ot.01')->create();
        $member->unsetRelation('industrialFactory');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(route('api.v1.provider-directory'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($queriesFor(2))->toBe($queriesFor(4));
});

describe('provider profile', function () {
    it('opens the public profile of an eligible provider', function () {
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create([
            'name' => 'Eligible Foods',
            'email' => 'sales@eligible.example',
        ]);
        actingAsMemberOfFactoryIn('food');

        $response = $this->getJson(route('api.v1.provider-directory.show', $provider));

        $response->assertOk()
            ->assertJsonPath('data.name', 'Eligible Foods')
            ->assertJsonPath('data.services.0.code', 'erp_business_applications.01');
        expect($response->json('data'))->not->toHaveKeys(['email', 'phone', 'representative_name', 'job_title', 'approval']);
    });

    it('returns 404 for a provider the factory cannot find in the directory', function (Closure $makeProvider) {
        actingAsMemberOfFactoryIn('food');

        $this->getJson(route('api.v1.provider-directory.show', $makeProvider()))->assertNotFound();
    })->with([
        'another sector' => [fn () => ServiceProvider::factory()->approved()->inSectors('chemical')->offering('erp_business_applications.01')->create()],
        'not approved' => [fn () => ServiceProvider::factory()->inSectors('food')->offering('erp_business_applications.01')->create()],
        'suspended' => [fn () => ServiceProvider::factory()->withApprovalStatus(ProviderApprovalStatus::Suspended)->inSectors('food')->offering('erp_business_applications.01')->create()],
        'no approved listing (ADR-025)' => [fn () => ServiceProvider::factory()->approved()->inSectors('food')->create()],
    ]);

    it('returns 404 for a provider that does not exist', function () {
        actingAsMemberOfFactoryIn('food');

        $this->getJson(route('api.v1.provider-directory.show', 999999))->assertNotFound();
    });

    it('opens any approved provider for an IMC administrator', function () {
        $provider = ServiceProvider::factory()->approved()->inSectors('chemical')->offering('erp_business_applications.01')->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->getJson(route('api.v1.provider-directory.show', $provider))->assertOk();
    });

    it('returns 403 to provider members', function () {
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
        Sanctum::actingAs(User::factory()->providerMember()->create());

        $this->getJson(route('api.v1.provider-directory.show', $provider))->assertForbidden();
    });
});

it('shows contact details in the list and profile only when the owner enables it (OQ-37)', function () {
    config(['jahez.providers.directory_shows_contact_details' => true]);
    $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create([
        'representative_name' => 'Mona Adel',
        'job_title' => 'Sales Director',
        'email' => 'sales@eligible.example',
        'phone' => '+20 2 1234 5678',
    ]);
    actingAsMemberOfFactoryIn('food');

    $this->getJson(route('api.v1.provider-directory'))
        ->assertJsonPath('data.0.email', 'sales@eligible.example')
        ->assertJsonPath('data.0.representative_name', 'Mona Adel');
    $this->getJson(route('api.v1.provider-directory.show', $provider))
        ->assertJsonPath('data.phone', '+20 2 1234 5678')
        ->assertJsonPath('data.job_title', 'Sales Director');
});

describe('recommended filter', function () {
    it('keeps only eligible providers that offer a service recommended for the factory\'s current category', function () {
        $member = actingAsMemberOfFactoryIn('food');
        storedReadinessAssessment($member->industrialFactory, 'b');
        $recommended = ServiceProvider::factory()->approved()->inSectors('food')->offering('automation_ot.01')->create(['name' => 'MES House']);
        ServiceProvider::factory()->approved()->inSectors('food')->offering('digital_engineering_smart_manufacturing.01')->create(['name' => 'Twin Only']);
        ServiceProvider::factory()->approved()->inSectors('chemical')->offering('automation_ot.01')->create(['name' => 'Other Sector']);

        $response = $this->getJson(route('api.v1.provider-directory', ['filter' => ['recommended' => 1]]));

        $response->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $recommended->id);
    });

    it('never lists a recommended-service provider that IMC has not approved', function (ProviderApprovalStatus $status) {
        $member = actingAsMemberOfFactoryIn('food');
        storedReadinessAssessment($member->industrialFactory, 'b');
        ServiceProvider::factory()->withApprovalStatus($status)->inSectors('food')->offering('automation_ot.01')->create();

        $this->getJson(route('api.v1.provider-directory', ['filter' => ['recommended' => 1]]))->assertOk()->assertJsonPath('meta.total', 0);
    })->with([
        'pending' => [ProviderApprovalStatus::Pending],
        'rejected' => [ProviderApprovalStatus::Rejected],
        'suspended' => [ProviderApprovalStatus::Suspended],
    ]);

    it('follows the category of the latest assessment', function () {
        $member = actingAsMemberOfFactoryIn('food');
        $this->travelTo('2026-09-01 08:00:00');
        storedReadinessAssessment($member->industrialFactory, 'b');
        $this->travelTo('2026-10-01 08:00:00');
        storedReadinessAssessment($member->industrialFactory, 'd');
        ServiceProvider::factory()->approved()->inSectors('food')->offering('automation_ot.01')->create(['name' => 'Basic Only']);
        $smart = ServiceProvider::factory()->approved()->inSectors('food')->offering('digital_engineering_smart_manufacturing.01')->create(['name' => 'Smart']);

        $response = $this->getJson(route('api.v1.provider-directory', ['filter' => ['recommended' => 1]]));

        expect($response->json('data.*.id'))->toBe([$smart->id]);
    });

    it('rejects the filter before the factory has completed an assessment', function () {
        Sanctum::actingAs(User::factory()->factoryMember(Factory::factory()->inSectors('food')->create())->create());

        $this->getJson(route('api.v1.provider-directory', ['filter' => ['recommended' => 1]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filter.recommended' => 'Complete your factory\'s readiness assessment to see its recommendations.']);
    });

    it('rejects the filter for accounts without a factory', function () {
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->getJson(route('api.v1.provider-directory', ['filter' => ['recommended' => 1]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filter.recommended' => 'Only factory members can list what is recommended for their factory.']);
    });
});
