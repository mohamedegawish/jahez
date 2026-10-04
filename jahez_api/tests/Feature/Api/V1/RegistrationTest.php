<?php

use App\Enums\AuditEvent;
use App\Enums\ProviderApprovalStatus;
use App\Enums\Role;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Jobs\RegisterOrganization;
use App\Jobs\SendAccountInvitation;
use App\Models\AuditLog;
use App\Models\Factory;
use App\Models\OrganizationDocument;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Notifications\RegistrationForExistingAccount;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    Storage::fake('local');
    Notification::fake();
});

/**
 * A complete factory registration form.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function factoryRegistration(array $overrides = []): array
{
    return [
        'name' => 'مصنع النيل للأغذية',
        'legal_name' => 'شركة النيل للصناعات الغذائية ش.م.م',
        'size' => 'medium',
        'sectors' => ['food'],
        'contact_name' => 'Mona Hassan',
        'contact_job_title' => 'Digital transformation lead',
        'contact_email' => 'mona@nile-foods.example',
        'contact_phone' => '+20 100 000 0000',
        'governorate' => 'القاهرة',
        'city' => 'العبور',
        'address' => 'المنطقة الصناعية الأولى',
        'commercial_registration_number' => '123456',
        'tax_registration_number' => '987-654-321',
        ...$overrides,
    ];
}

/**
 * A complete provider registration form.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function providerRegistration(array $overrides = []): array
{
    return [
        'name' => 'Delta Automation',
        'legal_name' => 'Delta Automation Systems S.A.E.',
        'description' => 'Industrial automation and MES integration.',
        'representative_name' => 'Omar Adel',
        'job_title' => 'CEO',
        'email' => 'omar@delta-automation.example',
        'phone' => '+20 2 1234 5678',
        'website' => 'https://delta-automation.example',
        'dx_experience_years' => 8,
        'governorate' => 'الجيزة',
        'city' => 'السادس من أكتوبر',
        'address' => 'المنطقة الصناعية الثالثة',
        'commercial_registration_number' => '445566',
        'tax_registration_number' => '112-233-445',
        'sectors' => ['food', 'chemical'],
        'services' => ['automation_ot.01', 'erp_business_applications.01'],
        ...$overrides,
    ];
}

/**
 * An upload whose content type is detected from its bytes, as for a real request. (The
 * fake files of UploadedFile::fake() report the type their name suggests.)
 */
function uploadedFileWithContent(string $clientName, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upload');
    file_put_contents($path, $content);

    return new UploadedFile($path, $clientName, null, null, true);
}

describe('options', function () {
    it('gives visitors the sectors, sizes, catalog and upload limits without a token', function () {
        $response = $this->getJson(route('api.v1.registration.options'))->assertOk();

        expect($response->json('data.sectors.*.code'))->toContain('food')
            ->and($response->json('data.factory_sizes.*.code'))->toBe(['small', 'medium', 'large'])
            ->and($response->json('data.service_categories'))->toHaveCount(7)
            ->and($response->json('data.documents.logo.extensions'))->toBe(['jpg', 'jpeg', 'png', 'webp'])
            ->and($response->json('data.documents.legal.extensions'))->toBe(['pdf', 'jpg', 'jpeg', 'png']);
    });
});

describe('factory registration', function () {
    it('creates the factory, its first member and its documents, and emails the set-password link', function () {
        $response = $this->post(route('api.v1.registration.factories'), factoryRegistration([
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            'commercial_registration_document' => UploadedFile::fake()->create('cr.pdf', 300, 'application/pdf'),
        ]), ['Accept' => 'application/json']);

        $response->assertAccepted()->assertExactJson(['data' => ['message' => RegistrationController::RESPONSE_MESSAGE]]);

        $factory = Factory::query()->sole();
        $member = User::query()->where('email', 'mona@nile-foods.example')->sole();
        expect($factory->only(['name', 'legal_name', 'size', 'contact_name', 'contact_email', 'governorate', 'commercial_registration_number', 'tax_registration_number']))->toBe([
            'name' => 'مصنع النيل للأغذية',
            'legal_name' => 'شركة النيل للصناعات الغذائية ش.م.م',
            'size' => 'medium',
            'contact_name' => 'Mona Hassan',
            'contact_email' => 'mona@nile-foods.example',
            'governorate' => 'القاهرة',
            'commercial_registration_number' => '123456',
            'tax_registration_number' => '987-654-321',
        ])
            ->and($factory->sectors()->pluck('code')->all())->toBe(['food'])
            ->and($member->role)->toBe(Role::FactoryMember)
            ->and($member->factory_id)->toBe($factory->id)
            ->and($member->service_provider_id)->toBeNull()
            ->and($member->name)->toBe('Mona Hassan');

        $documents = OrganizationDocument::query()->where('factory_id', $factory->id)->orderBy('type')->get();
        expect($documents->map(fn (OrganizationDocument $document): array => [$document->type->value, $document->status->value])->all())
            ->toBe([['commercial_registration', 'active'], ['logo', 'active']]);
        foreach ($documents as $document) {
            Storage::disk('local')->assertExists($document->path);
            expect($document->path)->toStartWith('documents/registrations/');
        }

        Notification::assertSentTo($member, AccountInvitation::class);
    });

    it('lets the new member sign in only after setting a password through the emailed link', function () {
        $this->post(route('api.v1.registration.factories'), factoryRegistration(), ['Accept' => 'application/json'])->assertAccepted();
        $member = User::query()->where('email', 'mona@nile-foods.example')->sole();

        $this->postJson(route('api.v1.auth.login'), ['email' => 'mona@nile-foods.example', 'password' => 'a guessed password', 'device_name' => 'web'])->assertUnprocessable();

        $token = null;
        Notification::assertSentTo($member, AccountInvitation::class, function (AccountInvitation $invitation) use (&$token): bool {
            $token = $invitation->token;

            return true;
        });
        $this->postJson(route('api.v1.auth.password.store'), [
            'token' => $token,
            'email' => 'mona@nile-foods.example',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ])->assertOk();

        $this->postJson(route('api.v1.auth.login'), ['email' => 'mona@nile-foods.example', 'password' => 'correct horse battery staple', 'device_name' => 'web'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'factory_member')
            ->assertJsonPath('data.user.organization.type', 'factory');
    });

    it('records the registration in the audit log with the address it came from', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->post(route('api.v1.registration.factories'), factoryRegistration(), ['Accept' => 'application/json'])
            ->assertAccepted();

        $entry = AuditLog::query()->where('event', AuditEvent::FactoryRegistered)->sole();
        expect($entry->actor_user_id)->toBe(User::query()->sole()->id)
            ->and($entry->subject_id)->toBe(Factory::query()->sole()->id)
            ->and($entry->ip_address)->toBe('203.0.113.20')
            ->and(Arr::except($entry->metadata, 'registration_id'))->toEqual(['name' => 'مصنع النيل للأغذية', 'sectors' => ['food'], 'documents' => []])
            ->and(Str::isUuid($entry->metadata['registration_id'] ?? null))->toBeTrue();
    });

    it('requires the name, a sector and the contact person\'s name and email', function () {
        $this->postJson(route('api.v1.registration.factories'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'sectors', 'contact_name', 'contact_email']);

        $this->postJson(route('api.v1.registration.factories'), factoryRegistration(['sectors' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sectors']);
        $this->assertDatabaseCount('factories', 0);
    });

    it('rejects unknown sectors, sizes and malformed details', function () {
        $this->postJson(route('api.v1.registration.factories'), factoryRegistration([
            'sectors' => ['space_mining'],
            'size' => 'gigantic',
            'contact_email' => 'not-an-email',
            'website' => 'javascript:alert(1)',
            'commercial_registration_number' => '12<script>',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sectors.0', 'size', 'contact_email', 'website', 'commercial_registration_number']);
    });

    it('ignores a role, organization or approval sent with the form', function () {
        $this->post(route('api.v1.registration.factories'), factoryRegistration([
            'role' => 'imc_admin',
            'factory_id' => 999,
            'service_provider_id' => 999,
        ]), ['Accept' => 'application/json'])->assertAccepted();

        $member = User::query()->sole();
        expect($member->role)->toBe(Role::FactoryMember)
            ->and($member->factory_id)->toBe(Factory::query()->sole()->id)
            ->and($member->service_provider_id)->toBeNull();
    });
});

describe('provider registration', function () {
    it('creates a pending provider that factories cannot see until IMC approves it', function () {
        $this->post(route('api.v1.registration.service-providers'), providerRegistration([
            'approval_status' => 'approved',
            'tax_registration_document' => UploadedFile::fake()->create('tax.pdf', 200, 'application/pdf'),
        ]), ['Accept' => 'application/json'])->assertAccepted();

        $provider = ServiceProvider::query()->sole();
        $member = User::query()->where('email', 'omar@delta-automation.example')->sole();
        expect($provider->approval_status)->toBe(ProviderApprovalStatus::Pending)
            ->and($provider->only(['name', 'legal_name', 'description', 'representative_name', 'email', 'dx_experience_years', 'commercial_registration_number']))->toBe([
                'name' => 'Delta Automation',
                'legal_name' => 'Delta Automation Systems S.A.E.',
                'description' => 'Industrial automation and MES integration.',
                'representative_name' => 'Omar Adel',
                'email' => 'omar@delta-automation.example',
                'dx_experience_years' => 8,
                'commercial_registration_number' => '445566',
            ])
            ->and($provider->services()->pluck('code')->sort()->values()->all())->toBe(['automation_ot.01', 'erp_business_applications.01'])
            ->and($member->role)->toBe(Role::ProviderMember)
            ->and($member->service_provider_id)->toBe($provider->id)
            ->and($provider->documents()->sole()->type->value)->toBe('tax_registration');
        Notification::assertSentTo($member, AccountInvitation::class);
        expect(AuditLog::query()->where('event', AuditEvent::ServiceProviderRegistered)->sole()->metadata['documents'])->toBe(['tax_registration']);

        $factory = Factory::factory()->inSectors('food')->create();
        Sanctum::actingAs(User::factory()->factoryMember($factory)->create());
        $this->getJson(route('api.v1.provider-directory'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('api.v1.provider-directory.show', $provider))->assertNotFound();
    });

    it('requires the company name and the representative\'s name and email', function () {
        $this->postJson(route('api.v1.registration.service-providers'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'representative_name', 'email']);
    });

    it('rejects an unknown catalog service', function () {
        $this->postJson(route('api.v1.registration.service-providers'), providerRegistration(['services' => ['not_a_service']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['services.0']);
    });
});

describe('uploads', function () {
    it('rejects files whose content is not an allowed type, whatever their name', function (string $field, Closure $makeFile) {
        $this->post(route('api.v1.registration.service-providers'), providerRegistration([$field => $makeFile()]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('service_providers', 0);
        expect(Storage::disk('local')->allFiles())->toBe([]);
    })->with([
        'an SVG logo' => ['logo', fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
        'a PDF logo' => ['logo', fn () => UploadedFile::fake()->create('logo.pdf', 50, 'application/pdf')],
        'a script named .pdf' => ['commercial_registration_document', fn () => uploadedFileWithContent('cr.pdf', '<?php echo "x";')],
        'HTML named .png' => ['logo', fn () => uploadedFileWithContent('logo.png', '<html><script>alert(1)</script></html>')],
        'an executable' => ['tax_registration_document', fn () => UploadedFile::fake()->create('tax.exe', 50, 'application/x-msdownload')],
    ]);

    it('rejects files over the size limit', function () {
        $this->post(route('api.v1.registration.factories'), factoryRegistration([
            'logo' => UploadedFile::fake()->image('logo.png')->size(3000),
            'tax_registration_document' => UploadedFile::fake()->create('tax.pdf', 6000, 'application/pdf'),
        ]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['logo', 'tax_registration_document']);
    });
});

describe('existing accounts', function () {
    it('answers exactly as for a new email, creates nothing and tells the account owner', function () {
        $existing = User::factory()->create(['email' => 'mona@nile-foods.example']);
        $factoriesBefore = Factory::query()->count();

        $response = $this->post(route('api.v1.registration.factories'), factoryRegistration([
            'contact_email' => 'MONA@nile-foods.example',
            'logo' => UploadedFile::fake()->image('logo.png'),
        ]), ['Accept' => 'application/json']);

        $response->assertAccepted()->assertExactJson(['data' => ['message' => RegistrationController::RESPONSE_MESSAGE]]);
        $this->assertDatabaseCount('factories', $factoriesBefore);
        $this->assertDatabaseCount('organization_documents', 0);
        expect(User::query()->count())->toBe(1)
            ->and(Storage::disk('local')->allFiles())->toBe([])
            ->and(AuditLog::query()->where('event', AuditEvent::FactoryRegistered)->count())->toBe(0);
        Notification::assertSentTo($existing, RegistrationForExistingAccount::class);
        Notification::assertNotSentTo($existing, AccountInvitation::class);
    });
});

describe('abuse limits', function () {
    it('limits registrations per address', function () {
        config(['jahez.registration.per_hour_per_ip' => 2]);

        foreach (['a', 'b'] as $suffix) {
            $this->post(route('api.v1.registration.factories'), factoryRegistration(['contact_email' => "{$suffix}@example.test"]), ['Accept' => 'application/json'])->assertAccepted();
        }

        $this->post(route('api.v1.registration.factories'), factoryRegistration(['contact_email' => 'c@example.test']), ['Accept' => 'application/json'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
        $this->assertDatabaseCount('factories', 2);
    });
});

describe('redelivery and failure handling', function () {
    /**
     * Posts a registration with two documents while the queue is faked, and returns the job
     * the controller queued, so a test can run it the way a worker would.
     */
    function queuedFactoryRegistration(): RegisterOrganization
    {
        Queue::fake([RegisterOrganization::class, SendAccountInvitation::class]);

        test()->post(route('api.v1.registration.factories'), factoryRegistration([
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            'commercial_registration_document' => UploadedFile::fake()->create('cr.pdf', 300, 'application/pdf'),
        ]), ['Accept' => 'application/json'])->assertAccepted();

        $job = Queue::pushed(RegisterOrganization::class)->first();
        expect($job)->toBeInstanceOf(RegisterOrganization::class);

        return $job;
    }

    it('keeps the organization and its files when the same job is delivered twice', function () {
        $job = queuedFactoryRegistration();

        $job->handle();
        $job->handle(); // redelivery: the worker died after the commit, before acknowledging

        $member = User::query()->where('email', 'mona@nile-foods.example')->sole();
        expect(Factory::query()->count())->toBe(1)
            ->and(User::query()->count())->toBe(1)
            ->and(OrganizationDocument::query()->count())->toBe(2);
        foreach (OrganizationDocument::query()->get() as $document) {
            Storage::disk('local')->assertExists($document->path);
        }
        Notification::assertNotSentTo($member, RegistrationForExistingAccount::class);
        Queue::assertPushed(SendAccountInvitation::class);
    });

    it('still sends the invitation when a retry follows a first run that committed but could not queue it', function () {
        $job = queuedFactoryRegistration();
        $job->handle();
        Queue::fake([RegisterOrganization::class, SendAccountInvitation::class]); // forget the first invitation: it was lost

        $job->handle();

        Queue::assertPushed(SendAccountInvitation::class, 1);
        expect(OrganizationDocument::query()->count())->toBe(2);
    });

    it('does not re-invite a member who has already set a password', function () {
        $job = queuedFactoryRegistration();
        $job->handle();
        User::query()->where('email', 'mona@nile-foods.example')->update(['email_verified_at' => now()]);
        Queue::fake([RegisterOrganization::class, SendAccountInvitation::class]);

        $job->handle();

        Queue::assertNotPushed(SendAccountInvitation::class);
    });

    it('removes the staged uploads and creates nothing when the job fails for good', function () {
        $job = queuedFactoryRegistration();
        expect(Storage::disk('local')->allFiles())->toHaveCount(2);

        $job->failed(new RuntimeException('The queue gave up.'));

        expect(Storage::disk('local')->allFiles())->toBe([])
            ->and(Factory::query()->count())->toBe(0)
            ->and(User::query()->count())->toBe(0);
    });

    it('removes the staged uploads when the job cannot be queued', function () {
        Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('The queue is unavailable.'));

        $this->post(route('api.v1.registration.factories'), factoryRegistration([
            'logo' => UploadedFile::fake()->image('logo.png'),
            'commercial_registration_document' => UploadedFile::fake()->create('cr.pdf', 100, 'application/pdf'),
        ]), ['Accept' => 'application/json'])->assertServerError();

        expect(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('removes the files already staged when storing a later one fails', function () {
        $disk = Storage::fake('local');
        $calls = 0;
        $flaky = Mockery::mock($disk)->makePartial();
        $flaky->shouldReceive('putFileAs')->andReturnUsing(function (...$arguments) use ($disk, &$calls) {
            return ++$calls === 2 ? false : $disk->putFileAs(...$arguments);
        });
        Storage::set('local', $flaky);
        Queue::fake([RegisterOrganization::class]);

        $this->post(route('api.v1.registration.factories'), factoryRegistration([
            'logo' => UploadedFile::fake()->image('logo.png'),
            'commercial_registration_document' => UploadedFile::fake()->create('cr.pdf', 100, 'application/pdf'),
        ]), ['Accept' => 'application/json'])->assertServerError();

        expect($disk->allFiles())->toBe([]);
        Queue::assertNotPushed(RegisterOrganization::class);
    });
});
