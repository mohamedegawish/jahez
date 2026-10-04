<?php

use App\Billing\PolicyCalendar;
use App\Enums\AuditEvent;
use App\Enums\FactoryApprovalStatus;
use App\Enums\Permission;
use App\Enums\ProviderApprovalStatus;
use App\Enums\ProviderRequestStatus;
use App\Enums\ServiceListingStatus;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Jobs\SendPasswordResetLink;
use App\Models\Agreement;
use App\Models\AuditLog;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\ServiceProvider;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakePaymentGateway;

/**
 * The single audit entry for an event, with its decoded metadata.
 */
function onlyAuditEntry(AuditEvent $event): AuditLog
{
    $entries = AuditLog::query()->where('event', $event->value)->get();
    expect($entries)->toHaveCount(1);

    return $entries->firstOrFail();
}

describe('authentication', function () {
    it('records a successful login with the credential that was issued', function () {
        $user = User::factory()->create(['email' => 'member@example.test', 'password' => 'correct-horse-battery']);

        $this->postJson(route('api.v1.auth.login'), ['email' => 'member@example.test', 'password' => 'correct-horse-battery', 'device_name' => 'Laptop'])->assertOk();

        $entry = onlyAuditEntry(AuditEvent::LoginSucceeded);
        expect($entry->actor_user_id)->toBe($user->id)
            ->and($entry->metadata)->toMatchArray(['device_name' => 'Laptop'])
            ->and($entry->metadata)->toHaveKey('credential_id');
    });

    it('records a failed login with its internal reason', function (array $credentials, string $reason, bool $hasSubject) {
        [$email, $password] = $credentials;

        $this->postJson(route('api.v1.auth.login'), ['email' => $email, 'password' => $password, 'device_name' => 'Laptop'])->assertUnprocessable();

        $entry = onlyAuditEntry(AuditEvent::LoginFailed);
        expect($entry->metadata)->toEqual(['email' => $email, 'reason' => $reason])
            ->and($entry->subject_id !== null)->toBe($hasSubject)
            ->and($entry->actor_user_id)->toBeNull();
    })->with([
        'unknown account, as typed' => [['Nobody@Example.test', 'any-password-1'], 'unknown_account', false],
        'wrong password' => [fn (): array => [User::factory()->create(['password' => 'correct-horse-battery'])->email, 'wrong-password-1'], 'wrong_password', true],
        'deactivated account' => [fn (): array => [User::factory()->deactivated()->create(['password' => 'correct-horse-battery'])->email, 'correct-horse-battery'], 'deactivated_account', true],
    ]);

    it('records the first refused login of a lock period, not every refused request', function () {
        foreach (range(1, LoginRequest::MAX_FAILED_ATTEMPTS + 3) as $attempt) {
            $this->postJson(route('api.v1.auth.login'), ['email' => 'target@example.test', 'password' => 'wrong-password-1', 'device_name' => 'Bot']);
        }

        expect(onlyAuditEntry(AuditEvent::LoginThrottled)->metadata)->toEqual(['email' => 'target@example.test', 'limit' => 'address']);
    });

    it('records a logout', function () {
        $user = User::factory()->create();

        $this->withToken(bearerTokenFor($user))->postJson(route('api.v1.auth.logout'))->assertNoContent();

        expect(onlyAuditEntry(AuditEvent::LoggedOut)->actor_user_id)->toBe($user->id);
    });

    it('records a password reset request from the job, with the address that requested it', function () {
        $user = User::factory()->create();
        Notification::fake();

        (new SendPasswordResetLink($user->email, '203.0.113.9'))->handle();

        $entry = onlyAuditEntry(AuditEvent::PasswordResetRequested);
        expect($entry->subject_id)->toBe($user->id)
            ->and($entry->ip_address)->toBe('203.0.113.9')
            ->and($entry->metadata)->toEqual(['status' => 'passwords.sent']);
    });

    it('records a completed password reset', function () {
        $user = User::factory()->create(['email' => 'member@example.test']);

        $this->postJson(route('api.v1.auth.password.store'), [
            'token' => Password::broker()->createToken($user),
            'email' => 'member@example.test',
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertOk();

        expect(onlyAuditEntry(AuditEvent::PasswordResetCompleted)->subject_id)->toBe($user->id);
    });
});

describe('account administration', function () {
    it('records who created an account and with which role and organization', function () {
        Queue::fake();
        $admin = User::factory()->imcAdmin()->create();
        $factory = Factory::factory()->create();
        Sanctum::actingAs($admin);

        $this->postJson(route('api.v1.users.store'), ['name' => 'Mona Adel', 'email' => 'mona@example.test', 'role' => 'factory_member', 'factory_id' => $factory->id])->assertCreated();

        $entry = onlyAuditEntry(AuditEvent::UserCreated);
        expect($entry->actor_user_id)->toBe($admin->id)
            ->and($entry->metadata)->toEqual(['role' => 'factory_member', 'factory_id' => $factory->id, 'service_provider_id' => null]);
    });

    it('records renames, deactivations and reactivations', function () {
        $admin = User::factory()->imcAdmin()->create();
        $member = User::factory()->create(['name' => 'Old Name']);
        Sanctum::actingAs($admin);

        $this->patchJson(route('api.v1.users.update', $member), ['name' => 'New Name', 'is_active' => false])->assertOk();
        $this->patchJson(route('api.v1.users.update', $member), ['is_active' => true])->assertOk();

        expect(onlyAuditEntry(AuditEvent::UserRenamed)->metadata)->toEqual(['from' => 'Old Name', 'to' => 'New Name'])
            ->and(onlyAuditEntry(AuditEvent::UserDeactivated)->subject_id)->toBe($member->id)
            ->and(onlyAuditEntry(AuditEvent::UserReactivated)->actor_user_id)->toBe($admin->id);
    });

    it('records nothing when a change is refused', function () {
        $actingAdministrator = User::factory()->imcAdmin()->deactivated()->create();
        $lastActiveAdministrator = User::factory()->imcAdmin()->create();
        Sanctum::actingAs($actingAdministrator);

        $this->patchJson(route('api.v1.users.update', $lastActiveAdministrator), ['is_active' => false])->assertUnprocessable();

        $this->assertDatabaseCount('audit_logs', 0);
    });

    it('records nothing for an update that changes nothing', function () {
        $member = User::factory()->create(['name' => 'Same Name']);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->patchJson(route('api.v1.users.update', $member), ['name' => 'Same Name', 'is_active' => true])->assertOk();

        $this->assertDatabaseCount('audit_logs', 0);
    });

    it('records an administrator created from the console', function () {
        $this->artisan('app:create-admin', ['email' => 'admin@example.test', 'name' => 'Platform Admin'])
            ->expectsQuestion('Password', 'a-strong-passphrase')
            ->expectsQuestion('Confirm password', 'a-strong-passphrase')
            ->assertSuccessful();

        $entry = onlyAuditEntry(AuditEvent::AdministratorCreatedFromConsole);
        expect($entry->actor_user_id)->toBeNull()
            ->and($entry->subject_id)->toBe(User::query()->where('email', 'admin@example.test')->value('id'));
    });
});

describe('organizations', function () {
    it('records created and updated factories with what changed', function () {
        $this->seed(ReferenceDataSeeder::class);
        $admin = User::factory()->imcAdmin()->create();
        Sanctum::actingAs($admin);

        $factoryId = $this->postJson(route('api.v1.factories.store'), ['name' => 'Delta Foods', 'sectors' => ['food']])->json('data.id');
        $this->patchJson(route('api.v1.factories.update', $factoryId), ['name' => 'Delta Foods Group', 'sectors' => ['food', 'chemical']])->assertOk();

        expect(onlyAuditEntry(AuditEvent::FactoryCreated)->metadata)->toEqual(['name' => 'Delta Foods', 'sectors' => ['food']])
            ->and(onlyAuditEntry(AuditEvent::FactoryUpdated)->metadata)->toEqual([
                'name' => ['from' => 'Delta Foods', 'to' => 'Delta Foods Group'],
                'sectors' => ['from' => ['food'], 'to' => ['food', 'chemical']],
            ]);
    });

    it('records nothing when the sectors sent are the ones already assigned', function (string $routeName, string $modelClass) {
        $this->seed(ReferenceDataSeeder::class);
        $organization = $modelClass::factory()->inSectors('food', 'chemical')->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->patchJson(route($routeName, $organization), ['sectors' => ['chemical', 'food']])->assertOk();

        $this->assertDatabaseCount('audit_logs', 0);
    })->with([
        'factory' => ['api.v1.factories.update', Factory::class],
        'service provider' => ['api.v1.service-providers.update', ServiceProvider::class],
    ]);

    it('records created and updated service providers', function () {
        $serviceProvider = ServiceProvider::factory()->create(['name' => 'Old Name']);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->postJson(route('api.v1.service-providers.store'), ['name' => 'Nile Systems'])->assertCreated();
        $this->patchJson(route('api.v1.service-providers.update', $serviceProvider), ['name' => 'New Name'])->assertOk();

        expect(onlyAuditEntry(AuditEvent::ServiceProviderCreated)->metadata)->toEqual(['name' => 'Nile Systems', 'sectors' => [], 'services' => []])
            ->and(onlyAuditEntry(AuditEvent::ServiceProviderUpdated)->subject_id)->toBe($serviceProvider->id);
    });

    it('records a member editing their provider, naming contact fields without their values', function () {
        $this->seed(ReferenceDataSeeder::class);
        $serviceProvider = ServiceProvider::factory()->offering('erp_business_applications.01')->create(['phone' => '+20 2 1111 1111']);
        $member = User::factory()->providerMember($serviceProvider)->create();
        Sanctum::actingAs($member);

        $this->patchJson(route('api.v1.service-providers.update', $serviceProvider), [
            'phone' => '+20 2 2222 2222',
            'email' => 'contact@provider.example.test',
            'services' => ['erp_business_applications.01', 'automation_ot.01'],
        ])->assertOk();

        $entry = onlyAuditEntry(AuditEvent::ServiceProviderUpdated);
        expect($entry->actor_user_id)->toBe($member->id)
            ->and($entry->metadata)->toEqual([
                'fields' => ['email', 'phone'],
                'services' => ['from' => ['erp_business_applications.01'], 'to' => ['erp_business_applications.01', 'automation_ot.01']],
            ])
            ->and(json_encode($entry->metadata))->not->toContain('2222');
    });

    it('records a completed readiness assessment with its version, total and category, and no answers', function () {
        $this->seed(ReferenceDataSeeder::class);
        $factory = Factory::factory()->create();
        $member = User::factory()->factoryMember($factory)->create();
        Sanctum::actingAs($member);

        $id = $this->postJson(route('api.v1.factories.readiness-assessments.store', $factory), readinessPayload('c'))->assertCreated()->json('data.id');

        $entry = onlyAuditEntry(AuditEvent::ReadinessAssessmentCompleted);
        expect($entry->actor_user_id)->toBe($member->id)
            ->and($entry->subject_type)->toBe('factory')
            ->and($entry->subject_id)->toBe($factory->id)
            ->and($entry->metadata)->toEqual(['assessment_id' => $id, 'questionnaire_version' => 1, 'total_score' => 30, 'category' => 'advanced']);
    });

    it('records an approval decision with the previous status and the reason', function () {
        $serviceProvider = ServiceProvider::factory()->create();
        $admin = User::factory()->imcAdmin()->create();
        Sanctum::actingAs($admin);

        $this->postJson(route('api.v1.service-providers.approval', $serviceProvider), ['decision' => 'rejected', 'reason' => 'Missing references'])->assertOk();

        $entry = onlyAuditEntry(AuditEvent::ServiceProviderApprovalChanged);
        expect($entry->actor_user_id)->toBe($admin->id)
            ->and($entry->subject_id)->toBe($serviceProvider->id)
            ->and($entry->metadata)->toEqual(['from' => 'pending', 'to' => 'rejected', 'reason' => 'Missing references']);
    });
});

describe('marketplace', function () {
    it('records a service request with its service and providers', function () {
        $this->seed(ReferenceDataSeeder::class);
        $member = User::factory()->factoryMember(Factory::factory()->inSectors('food')->create())->create();
        $provider = ServiceProvider::factory()->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
        Sanctum::actingAs($member);

        $id = $this->postJson(route('api.v1.service-requests.store'), [
            'service' => 'erp_business_applications.01',
            'title' => 'ERP',
            'need' => 'Planning',
            'provider_ids' => [$provider->id],
        ])->json('data.id');

        $entry = onlyAuditEntry(AuditEvent::ServiceRequestCreated);
        expect($entry->actor_user_id)->toBe($member->id)
            ->and($entry->subject_type)->toBe('service_request')
            ->and($entry->subject_id)->toBe($id)
            ->and($entry->metadata)->toEqual(['service' => 'erp_business_applications.01', 'provider_ids' => [$provider->id]]);
    });

    it('records each provider answer with the status change', function () {
        ['threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1);
        Sanctum::actingAs($providerMembers[0]);

        $this->postJson(route('api.v1.provider-requests.accept', $threads[0]))->assertOk();

        $entry = onlyAuditEntry(AuditEvent::ProviderRequestAccepted);
        expect($entry->subject_type)->toBe('provider_request')
            ->and($entry->metadata)->toEqual(['from' => 'pending', 'to' => 'accepted', 'reason' => null]);
    });

    it('records a decline or a withdrawal with the status change and the reason', function (string $action, AuditEvent $event, string $to) {
        ['factoryMember' => $member, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        $actor = $action === 'decline' ? $providerMembers[0] : $member;
        Sanctum::actingAs($actor);

        $this->postJson(route("api.v1.provider-requests.{$action}", $threads[0]), ['reason' => 'Out of budget'])->assertOk();

        $entry = onlyAuditEntry($event);
        expect($entry->actor_user_id)->toBe($actor->id)
            ->and($entry->subject_type)->toBe('provider_request')
            ->and($entry->subject_id)->toBe($threads[0]->id)
            ->and($entry->metadata)->toEqual(['from' => 'accepted', 'to' => $to, 'reason' => 'Out of budget']);
    })->with([
        'decline by the provider' => ['decline', AuditEvent::ProviderRequestDeclined, 'declined'],
        'withdrawal by the factory' => ['withdraw', AuditEvent::ProviderRequestWithdrawn, 'withdrawn'],
    ]);

    it('records the agreement concluded by an acceptance, IMC\'s review, and each contract draft and cancellation', function () {
        ['factoryMember' => $member, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(1, ProviderRequestStatus::Accepted);
        $offer = offerVersion($threads[0], 1, $providerMembers[0], '123456.78');
        Sanctum::actingAs($member);
        $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[0], $offer]))->assertOk();
        $agreement = Agreement::query()->sole();
        $admin = User::factory()->imcAdmin()->create();
        Sanctum::actingAs($admin);
        $this->postJson(route('api.v1.agreements.review', $agreement), ['decision' => 'approved'])->assertOk();
        expect(onlyAuditEntry(AuditEvent::AgreementReviewed)->metadata)->toEqual(['decision' => 'approved', 'reason' => null])
            ->and(onlyAuditEntry(AuditEvent::AgreementReviewed)->actor_user_id)->toBe($admin->id);

        Sanctum::actingAs($member);
        $contractId = $this->postJson(route('api.v1.agreements.contracts.store', $agreement), [
            'knowledge_transfer' => ['trainees' => 2, 'training_plan' => 'Private training plan'],
        ])->assertCreated()->json('data.id');
        $this->postJson(route('api.v1.contracts.cancel', $contractId), ['reason' => 'Revise'])->assertOk();

        expect(onlyAuditEntry(AuditEvent::AgreementConcluded)->metadata)->toEqual(['provider_request_id' => $threads[0]->id, 'offer_version' => 1])
            ->and(onlyAuditEntry(AuditEvent::AgreementConcluded)->subject_type)->toBe('agreement')
            ->and(onlyAuditEntry(AuditEvent::ContractDrafted)->metadata)->toEqual(['agreement_id' => $agreement->id, 'version' => 1])
            ->and(onlyAuditEntry(AuditEvent::ContractCancelled)->metadata)->toEqual(['agreement_id' => $agreement->id, 'version' => 1, 'reason' => 'Revise'])
            ->and(AuditLog::query()->get()->toJson())->not->toContain('123456')->not->toContain('Private training plan');
    });

    it('records invoice drafting, issuing and cancelling, and payments, never the amounts', function () {
        configureBilling([
            'jahez.billing.payment_gateway' => 'fake',
            'jahez.billing.gateways' => ['fake' => FakePaymentGateway::class],
        ]);
        ['invoice' => $invoice, 'factoryMember' => $member, 'providerMembers' => $providerMembers] = draftInvoice();
        $this->postJson(route('api.v1.invoices.issue', $invoice))->assertOk();
        Sanctum::actingAs($member);
        $paymentId = $this->postJson(route('api.v1.invoices.payments.store', $invoice), [], ['Idempotency-Key' => 'audit-payment-1'])->json('data.id');
        [$json, $signature] = FakePaymentGateway::signedCallback(['event_id' => 'evt-9', 'reference' => "fake-{$paymentId}", 'status' => 'paid', 'amount' => '285000.00', 'currency' => 'EGP']);
        $this->call('POST', route('api.v1.payment-gateways.callback', 'fake'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => $signature], $json)->assertOk();
        Sanctum::actingAs($providerMembers[0]);
        ['invoice' => $draft] = draftInvoice();
        $this->postJson(route('api.v1.invoices.cancel', $draft), ['reason' => 'Duplicate'])->assertOk();

        expect(AuditLog::query()->where('event', AuditEvent::InvoiceDrafted->value)->count())->toBe(2)
            ->and(onlyAuditEntry(AuditEvent::InvoiceIssued)->metadata)->toEqual(['number' => 'JZ-000001', 'policy_versions' => [
                'invoicing' => $invoice->refresh()->invoicing_policy_version_id,
                'tax' => $invoice->tax_policy_version_id,
                'payment_terms' => $invoice->payment_terms_policy_version_id,
            ]])
            ->and(onlyAuditEntry(AuditEvent::InvoiceCancelled)->metadata)->toEqual(['reason' => 'Duplicate'])
            ->and(onlyAuditEntry(AuditEvent::PaymentInitiated)->actor_user_id)->toBe($member->id)
            ->and(onlyAuditEntry(AuditEvent::PaymentStatusChanged)->actor_user_id)->toBeNull()
            ->and(onlyAuditEntry(AuditEvent::PaymentStatusChanged)->metadata)->toEqual([
                'invoice_id' => $invoice->id, 'from' => 'pending', 'to' => 'succeeded', 'source' => 'callback', 'gateway_event_id' => 'evt-9', 'invoice_status' => 'paid',
            ])
            ->and(AuditLog::query()->get()->toJson())->not->toContain('285000')->not->toContain('250000');
    });

    it('records the providers added to an open request', function () {
        ['factoryMember' => $member, 'serviceRequest' => $serviceRequest] = marketplaceRequest(1, ProviderRequestStatus::Declined);
        $newcomers = ServiceProvider::factory()->count(2)->approved()->inSectors('food')->offering('erp_business_applications.01')->create();
        Sanctum::actingAs($member);

        $this->postJson(route('api.v1.service-requests.providers.store', $serviceRequest), ['provider_ids' => [$newcomers[1]->id, $newcomers[0]->id]])->assertOk();

        $entry = onlyAuditEntry(AuditEvent::ServiceRequestProvidersAdded);
        expect($entry->actor_user_id)->toBe($member->id)
            ->and($entry->subject_id)->toBe($serviceRequest->id)
            ->and($entry->metadata)->toEqual(['provider_ids' => [$newcomers[0]->id, $newcomers[1]->id]]);
    });

    it('records a cancellation with the reason and the threads it closed', function () {
        ['factoryMember' => $member, 'serviceRequest' => $serviceRequest, 'threads' => $threads] = marketplaceRequest(2);
        $threads[1]->moveTo(ProviderRequestStatus::Declined);
        Sanctum::actingAs($member);

        $this->postJson(route('api.v1.service-requests.cancel', $serviceRequest), ['reason' => 'Budget frozen'])->assertOk();

        $entry = onlyAuditEntry(AuditEvent::ServiceRequestCancelled);
        expect($entry->actor_user_id)->toBe($member->id)
            ->and($entry->subject_type)->toBe('service_request')
            ->and($entry->subject_id)->toBe($serviceRequest->id)
            ->and($entry->metadata)->toEqual(['reason' => 'Budget frozen', 'closed_provider_request_ids' => [$threads[0]->id]]);
    });

    it('records offer versions and acceptance, but never the price or terms', function () {
        ['factoryMember' => $member, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(2, ProviderRequestStatus::Accepted);
        Sanctum::actingAs($providerMembers[0]);
        $offerId = $this->postJson(route('api.v1.provider-requests.offers.store', $threads[0]), [
            'based_on_version' => null,
            'scope' => 'Secret scope wording',
            'deliverables' => 'Secret deliverables',
            'duration_days' => 30,
            'price' => ['amount' => '987654.32', 'currency' => 'EGP'],
        ])->json('data.id');
        Sanctum::actingAs($member);
        $this->postJson(route('api.v1.provider-requests.offers.accept', [$threads[0], $offerId]))->assertOk();

        expect(onlyAuditEntry(AuditEvent::OfferSubmitted)->metadata)->toEqual(['version' => 1])
            ->and(onlyAuditEntry(AuditEvent::OfferAccepted)->metadata)->toEqual(['version' => 1, 'closed_provider_request_ids' => [$threads[1]->id]])
            ->and(AuditLog::query()->get()->toJson())->not->toContain('987654')->not->toContain('Secret');
    });
});

describe('atomicity', function () {
    beforeEach(function () {
        AuditLog::creating(fn (): never => throw new RuntimeException('Audit store unavailable.'));
    });

    it('issues no token when the login cannot be audited', function () {
        User::factory()->create(['email' => 'member@example.test', 'password' => 'correct-horse-battery']);

        $response = $this->postJson(route('api.v1.auth.login'), ['email' => 'member@example.test', 'password' => 'correct-horse-battery', 'device_name' => 'Laptop']);

        $response->assertServerError();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    });

    it('keeps the token when the logout cannot be audited', function () {
        $token = bearerTokenFor(User::factory()->create());

        $response = $this->withToken($token)->postJson(route('api.v1.auth.logout'));

        $response->assertServerError();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    });

    it('keeps the old password and the link when the password reset cannot be audited', function () {
        $user = User::factory()->create(['email' => 'member@example.test', 'password' => 'correct-horse-battery']);
        $resetToken = Password::broker()->createToken($user);

        $response = $this->postJson(route('api.v1.auth.password.store'), [
            'token' => $resetToken,
            'email' => 'member@example.test',
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ]);

        $response->assertServerError();
        expect(Hash::check('correct-horse-battery', $user->fresh()->password ?? ''))->toBeTrue()
            ->and(Password::broker()->tokenExists($user, $resetToken))->toBeTrue();
    });
});

it('never stores passwords or reset tokens in the audit log', function () {
    $user = User::factory()->create(['email' => 'member@example.test', 'password' => 'correct-horse-battery']);
    $resetToken = Password::broker()->createToken($user);
    $this->postJson(route('api.v1.auth.login'), ['email' => 'member@example.test', 'password' => 'wrong-guess-password', 'device_name' => 'Laptop']);
    $this->postJson(route('api.v1.auth.login'), ['email' => 'member@example.test', 'password' => 'correct-horse-battery', 'device_name' => 'Laptop']);
    $this->postJson(route('api.v1.auth.password.store'), [
        'token' => $resetToken,
        'email' => 'member@example.test',
        'password' => 'a-brand-new-passphrase',
        'password_confirmation' => 'a-brand-new-passphrase',
    ])->assertOk();

    $everything = AuditLog::query()->get()->toJson();

    expect(AuditLog::query()->count())->toBe(3)
        ->and($everything)
        ->not->toContain('correct-horse-battery')
        ->not->toContain('wrong-guess-password')
        ->not->toContain('a-brand-new-passphrase')
        ->not->toContain($resetToken);
});

describe('profiles, reviews and evaluations', function () {
    it('records a size change with its previous value, and nothing when it does not change', function () {
        $factory = Factory::factory()->create(['size' => 'small']);
        $admin = User::factory()->imcAdmin()->create();
        Sanctum::actingAs($admin);

        $this->patchJson(route('api.v1.factories.update', $factory), ['size' => 'medium'])->assertOk();
        $this->patchJson(route('api.v1.factories.update', $factory), ['size' => 'medium'])->assertOk();

        expect(onlyAuditEntry(AuditEvent::FactoryUpdated)->metadata)->toEqual(['size' => ['from' => 'small', 'to' => 'medium']]);
    });

    it('records a review request with the status change and the note', function () {
        $serviceProvider = ServiceProvider::factory()->withApprovalStatus(ProviderApprovalStatus::Rejected)->create();
        $member = User::factory()->providerMember($serviceProvider)->create();
        Sanctum::actingAs($member);

        $this->postJson(route('api.v1.service-providers.review-request', $serviceProvider), ['note' => 'References added'])->assertOk();

        $entry = onlyAuditEntry(AuditEvent::ServiceProviderReviewRequested);
        expect($entry->actor_user_id)->toBe($member->id)
            ->and($entry->subject_type)->toBe('service_provider')
            ->and($entry->metadata)->toEqual(['from' => 'rejected', 'to' => 'pending', 'note' => 'References added']);
    });

    it('records factory approval decisions and review requests with the status change and the reason', function () {
        $this->seed(ReferenceDataSeeder::class);
        $factory = Factory::factory()->withApprovalStatus(FactoryApprovalStatus::Pending)->inSectors('food')->create();
        $admin = User::factory()->imcAdmin()->create();
        $member = User::factory()->factoryMember($factory)->create();

        Sanctum::actingAs($admin);
        $this->postJson(route('api.v1.factories.approval', $factory), ['decision' => 'changes_requested', 'reason' => 'Upload the tax card'])->assertOk();
        Sanctum::actingAs($member);
        $this->postJson(route('api.v1.factories.review-request', $factory), ['note' => 'Uploaded'])->assertOk();

        $decision = onlyAuditEntry(AuditEvent::FactoryApprovalChanged);
        expect($decision->actor_user_id)->toBe($admin->id)
            ->and($decision->subject_type)->toBe('factory')
            ->and($decision->metadata)->toEqual(['from' => 'pending', 'to' => 'changes_requested', 'reason' => 'Upload the tax card'])
            ->and(onlyAuditEntry(AuditEvent::FactoryReviewRequested)->metadata)->toEqual(['from' => 'changes_requested', 'to' => 'pending', 'note' => 'Uploaded']);
    });

    it('records a listing review on the provider, naming the service, and nothing when it is refused', function () {
        $this->seed(ReferenceDataSeeder::class);
        $serviceProvider = ServiceProvider::factory()->listing(ServiceListingStatus::Pending, 'automation_ot.01')->create();
        $serviceId = CatalogService::query()->where('code', 'automation_ot.01')->value('id');
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->postJson(route('api.v1.service-providers.services.review', [$serviceProvider, $serviceId]), ['decision' => 'suspended', 'reason' => 'x'])->assertConflict();
        $this->postJson(route('api.v1.service-providers.services.review', [$serviceProvider, $serviceId]), ['decision' => 'rejected', 'reason' => 'Out of scope'])->assertOk();

        $entry = onlyAuditEntry(AuditEvent::ServiceListingReviewed);
        expect($entry->subject_type)->toBe('service_provider')
            ->and($entry->subject_id)->toBe($serviceProvider->id)
            ->and($entry->metadata)->toEqual(['service' => 'automation_ot.01', 'from' => 'pending', 'to' => 'rejected', 'reason' => 'Out of scope']);
    });

    it('records a listing resubmission on the provider with the note, and nothing when it is refused', function () {
        $this->seed(ReferenceDataSeeder::class);
        $serviceProvider = ServiceProvider::factory()->listing(ServiceListingStatus::Rejected, 'automation_ot.01')->create();
        $serviceId = CatalogService::query()->where('code', 'automation_ot.01')->value('id');
        $member = User::factory()->providerMember($serviceProvider)->create();
        Sanctum::actingAs($member);

        $this->postJson(route('api.v1.service-providers.services.resubmit', [$serviceProvider, $serviceId]), ['note' => 'Fixed'])->assertOk();
        $this->postJson(route('api.v1.service-providers.services.resubmit', [$serviceProvider, $serviceId]))->assertConflict();

        $entry = onlyAuditEntry(AuditEvent::ServiceListingResubmitted);
        expect($entry->actor_user_id)->toBe($member->id)
            ->and($entry->metadata)->toEqual(['service' => 'automation_ot.01', 'from' => 'rejected', 'to' => 'pending', 'note' => 'Fixed']);
    });

    it('records announcement changes with the title or field names only', function () {
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $id = $this->postJson(route('api.v1.announcements.store'), ['title' => 'Workshop', 'description' => 'Secretless', 'color' => 'blue'])->assertCreated()->json('data.id');
        $this->patchJson(route('api.v1.announcements.update', $id), ['description' => 'Changed text'])->assertOk();
        $this->postJson(route('api.v1.announcements.publish', $id))->assertOk();

        expect(onlyAuditEntry(AuditEvent::AnnouncementCreated)->subject_type)->toBe('public_announcement')
            ->and(onlyAuditEntry(AuditEvent::AnnouncementUpdated)->metadata)->toEqual(['fields' => ['description']])
            ->and(onlyAuditEntry(AuditEvent::AnnouncementPublished)->metadata)->toEqual(['title' => 'Workshop'])
            ->and(AuditLog::query()->get()->toJson())->not->toContain('Changed text');
    });

    it('records an evaluation with its version and total, never the written notes', function () {
        $this->seed(ReferenceDataSeeder::class);
        config(['jahez.providers.evaluation.scale_max' => 5]);
        $serviceProvider = ServiceProvider::factory()->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $criteria = [];
        foreach (['technical_expertise', 'technical_cloud_model', 'knowledge_transfer', 'financial_flexibility', 'technical_support_sla'] as $code) {
            $criteria[$code] = ['score' => 5, 'note' => "Confidential note on {$code}"];
        }

        $id = $this->postJson(route('api.v1.service-providers.evaluations.store', $serviceProvider), [
            'summary' => 'Confidential summary', 'evaluated_on' => '2026-10-01', 'criteria' => $criteria,
        ])->assertCreated()->json('data.id');

        expect(onlyAuditEntry(AuditEvent::ServiceProviderEvaluationRecorded)->metadata)->toEqual(['evaluation_id' => $id, 'criteria_version' => 1, 'weighted_total' => '100.00'])
            ->and(AuditLog::query()->get()->toJson())->not->toContain('Confidential');
    });
});

describe('onboarding and questionnaire administration', function () {
    it('records a change request from submission to rejection or cancellation, naming fields and never their values', function () {
        $this->seed(ReferenceDataSeeder::class);
        $serviceProvider = ServiceProvider::factory()->approved()->create(['legal_name' => 'Old Legal Name']);
        $member = User::factory()->providerMember($serviceProvider)->create();
        Sanctum::actingAs($member);

        $first = $this->postJson(route('api.v1.service-providers.change-requests.store', $serviceProvider), ['legal_name' => 'Secret New Name'])->json('data.id');
        $admin = User::factory()->imcAdmin()->create();
        Sanctum::actingAs($admin);
        $this->postJson(route('api.v1.service-providers.change-requests.reject', [$serviceProvider, $first]), ['reason' => 'Not matching the registry.'])->assertOk();
        Sanctum::actingAs($member);
        $second = $this->postJson(route('api.v1.service-providers.change-requests.store', $serviceProvider), ['tax_registration_number' => '42'])->json('data.id');
        $this->postJson(route('api.v1.service-providers.change-requests.cancel', [$serviceProvider, $second]))->assertOk();

        $submitted = AuditLog::query()->where('event', AuditEvent::ProviderChangeRequestSubmitted)->orderBy('id')->get();
        expect($submitted->map->metadata->all())->toEqual([
            ['change_request_id' => $first, 'fields' => ['legal_name'], 'documents' => []],
            ['change_request_id' => $second, 'fields' => ['tax_registration_number'], 'documents' => []],
        ])
            ->and($submitted->first()->actor_user_id)->toBe($member->id)
            ->and(onlyAuditEntry(AuditEvent::ProviderChangeRequestRejected)->metadata)->toEqual(['change_request_id' => $first, 'reason' => 'Not matching the registry.'])
            ->and(onlyAuditEntry(AuditEvent::ProviderChangeRequestRejected)->actor_user_id)->toBe($admin->id)
            ->and(onlyAuditEntry(AuditEvent::ProviderChangeRequestCancelled)->metadata)->toEqual(['change_request_id' => $second])
            ->and(AuditLog::query()->get()->toJson())->not->toContain('Secret New Name');
    });

    it('records edits and deletion of a questionnaire draft', function () {
        $this->seed(ReferenceDataSeeder::class);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $draft = $this->postJson(route('api.v1.readiness-questionnaires.store'))->json('data');
        $definition = [
            'title_ar' => $draft['title_ar'],
            'pillars' => array_map(fn (array $pillar): array => [
                'code' => $pillar['code'],
                'name_ar' => $pillar['name_ar'],
                'questions' => array_map(fn (array $question): array => [
                    'code' => $question['code'],
                    'text_ar' => $question['text_ar'],
                    'choices' => array_map(fn (array $choice): array => array_intersect_key($choice, array_flip(['code', 'label_ar', 'text_ar', 'points'])), $question['choices']),
                ], $pillar['questions']),
            ], $draft['definition']['pillars']),
            'categories' => array_map(fn (array $category): array => array_intersect_key($category, array_flip(['code', 'name_ar', 'name_en', 'description_ar', 'min_score', 'max_score', 'focus_ar', 'steps_ar'])), $draft['definition']['categories']),
        ];

        $this->putJson(route('api.v1.readiness-questionnaires.update', $draft['id']), $definition)->assertOk();
        $this->deleteJson(route('api.v1.readiness-questionnaires.destroy', $draft['id']))->assertNoContent();

        expect(onlyAuditEntry(AuditEvent::ReadinessQuestionnaireUpdated)->metadata)->toEqual([
            'version' => 2,
            'questions' => 10,
            'category_ranges' => ['b4_automation' => [10, 17], 'basic' => [18, 25], 'advanced' => [26, 33], 'smart' => [34, 40]],
        ])
            ->and(onlyAuditEntry(AuditEvent::ReadinessQuestionnaireUpdated)->subject_type)->toBe('readiness_questionnaire')
            ->and(onlyAuditEntry(AuditEvent::ReadinessQuestionnaireDeleted)->metadata)->toEqual(['version' => 2]);
    });

    it('records nothing when an upload or a change request is refused', function () {
        $this->seed(ReferenceDataSeeder::class);
        $serviceProvider = ServiceProvider::factory()->approved()->create();
        Sanctum::actingAs(User::factory()->providerMember($serviceProvider)->create());

        $this->postJson(route('api.v1.service-providers.change-requests.store', $serviceProvider), [])->assertUnprocessable();
        $this->postJson(route('api.v1.service-providers.documents.store', $serviceProvider), ['type' => 'logo'])->assertUnprocessable();

        expect(AuditLog::query()->whereIn('event', [AuditEvent::ProviderChangeRequestSubmitted, AuditEvent::OrganizationDocumentUploaded])->count())->toBe(0);
    });
});

describe('financial policies (ADR-023)', function () {
    it('records each step of a version with its actor, reason and changed values, inside the same transaction', function () {
        $maker = financeAdmin(Permission::FinancialPoliciesManage);
        $checker = financeAdmin(Permission::FinancialPoliciesApprove);
        Sanctum::actingAs($maker);
        $id = $this->postJson(route('api.v1.financial-policies.store'), [
            'kind' => 'revenue_share', 'scope_type' => 'global', 'name_ar' => 'حصة الوزارة',
            'parameters' => ['method' => 'percentage', 'rate_percent' => '10', 'base' => 'subtotal_before_tax'],
            'effective_from' => PolicyCalendar::today()->toDateString(), 'effective_to' => null, 'change_reason' => 'قرار رقم 5',
        ])->assertCreated()->json('data.id');
        $this->postJson(route('api.v1.financial-policy-versions.submit', $id))->assertOk();
        Sanctum::actingAs($checker);
        $this->postJson(route('api.v1.financial-policy-versions.reject', $id), ['reason' => 'النسبة تحتاج مراجعة'])->assertOk();

        expect(onlyAuditEntry(AuditEvent::FinancialPolicyCreated)->metadata)->toEqual(['kind' => 'revenue_share', 'scope' => 'global'])
            ->and(onlyAuditEntry(AuditEvent::FinancialPolicyCreated)->subject_type)->toBe('financial_policy')
            ->and(onlyAuditEntry(AuditEvent::FinancialPolicyVersionDrafted)->metadata)->toMatchArray(['version' => 1, 'change_reason' => 'قرار رقم 5'])
            ->and(onlyAuditEntry(AuditEvent::FinancialPolicyVersionSubmitted)->actor_user_id)->toBe($maker->id)
            ->and(onlyAuditEntry(AuditEvent::FinancialPolicyVersionRejected)->actor_user_id)->toBe($checker->id)
            ->and(onlyAuditEntry(AuditEvent::FinancialPolicyVersionRejected)->metadata)->toMatchArray(['reason' => 'النسبة تحتاج مراجعة'])
            ->and(onlyAuditEntry(AuditEvent::FinancialPolicyVersionRejected)->subject_type)->toBe('financial_policy_version');
    });

    it('writes no version and no entry when the audit entry cannot be written', function () {
        Sanctum::actingAs(financeAdmin(Permission::FinancialPoliciesManage));
        AuditLog::creating(fn (): never => throw new RuntimeException('Audit store unavailable.'));

        $this->withoutExceptionHandling();
        expect(fn () => $this->postJson(route('api.v1.financial-policies.store'), [
            'kind' => 'revenue_share', 'scope_type' => 'global', 'name_ar' => 'حصة الوزارة',
            'parameters' => ['method' => 'percentage', 'rate_percent' => '10', 'base' => 'subtotal_before_tax'],
            'effective_from' => PolicyCalendar::today()->toDateString(), 'effective_to' => null, 'change_reason' => 'قرار',
        ]))->toThrow(RuntimeException::class);

        $this->assertDatabaseCount('financial_policies', 0);
        $this->assertDatabaseCount('financial_policy_versions', 0);
    });
});
