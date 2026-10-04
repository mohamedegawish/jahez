<?php

namespace App\Jobs;

use App\Enums\AuditEvent;
use App\Enums\DocumentType;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\OrganizationDocument;
use App\Models\Sector;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use App\Notifications\RegistrationForExistingAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Completes a public registration (ADR-019) in the queue worker, so the HTTP response is
 * the same whether or not the email already has an account (ADR-011).
 *
 * - New email: creates the organization (a provider starts pending), its first member
 *   with a random password nobody knows, the uploaded documents and the audit entry in
 *   one transaction, then queues the set-password invitation.
 * - Email already registered: creates nothing, deletes the uploads, and tells the
 *   account owner that someone tried to register with their address.
 *
 * The payload holds the submitted details and the paths of the staged uploads, never a
 * password or token.
 *
 * Queues deliver at least once, so the job must survive running twice. The registration id
 * is written to the audit entry in the same transaction as the organization: a later run
 * that finds it knows its own registration is complete, keeps the files, and only makes
 * sure the invitation was queued (it may have been lost when the first run failed after
 * the commit).
 */
class RegisterOrganization implements ShouldQueue
{
    use Queueable;

    public const FACTORY = 'factory';

    public const SERVICE_PROVIDER = 'service_provider';

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying.
     *
     * @var list<int>
     */
    public array $backoff = [10, 60];

    /**
     * @param  self::FACTORY|self::SERVICE_PROVIDER  $kind
     * @param  array<string, mixed>  $details  validated organization fields
     * @param  array{sectors: list<string>, services: list<string>}  $codes
     * @param  array{name: string, email: string}  $account
     * @param  list<array{type: string, disk: string, path: string, original_name: string, mime_type: string, size_bytes: int, sha256: string}>  $documents
     */
    public function __construct(
        public readonly string $registrationId,
        public readonly string $kind,
        public readonly array $details,
        public readonly array $codes,
        public readonly array $account,
        public readonly array $documents,
        public readonly ?string $ipAddress,
    ) {}

    public function handle(): void
    {
        $completed = $this->memberOfCompletedRegistration();

        if ($completed !== null) {
            if ($completed->email_verified_at === null) {
                SendAccountInvitation::dispatch($completed);
            }

            return;
        }

        $existing = $this->existingAccount();

        if ($existing !== null) {
            $this->discardDocuments();
            if ($existing->isActive()) {
                $existing->notify(new RegistrationForExistingAccount);
            }

            return;
        }

        try {
            $member = DB::transaction(fn (): User => $this->kind === self::FACTORY ? $this->registerFactory() : $this->registerServiceProvider());
        } catch (UniqueConstraintViolationException) {
            // Another registration took the email first: treat it as an existing account.
            $this->discardDocuments();

            return;
        }

        SendAccountInvitation::dispatch($member);
    }

    /**
     * A permanently failed job leaves no staged upload behind.
     */
    public function failed(?Throwable $exception): void
    {
        $this->discardDocuments();
    }

    private function registerFactory(): User
    {
        $factory = new Factory;
        $factory->fill($this->details);
        $factory->save();
        $factory->sectors()->sync(Sector::query()->whereIn('code', $this->codes['sectors'])->pluck('id'));

        $member = $this->createMember(Role::FactoryMember, $factory);
        $documentTypes = $this->attachDocuments($factory, $member);

        AuditLog::record(AuditEvent::FactoryRegistered, $member, $factory, [
            'registration_id' => $this->registrationId,
            'name' => $factory->name,
            'sectors' => $this->codes['sectors'],
            'documents' => $documentTypes,
        ], $this->ipAddress);
        MarketplaceNotifications::organizationRegistered('factory', $factory->id, $factory->name);

        return $member;
    }

    private function registerServiceProvider(): User
    {
        $provider = new ServiceProvider;
        $provider->fill($this->details);
        $provider->save();
        $provider->sectors()->sync(Sector::query()->whereIn('code', $this->codes['sectors'])->pluck('id'));
        $provider->services()->sync(CatalogService::query()->whereIn('code', $this->codes['services'])->pluck('id'));

        $member = $this->createMember(Role::ProviderMember, $provider);
        $documentTypes = $this->attachDocuments($provider, $member);

        AuditLog::record(AuditEvent::ServiceProviderRegistered, $member, $provider, [
            'registration_id' => $this->registrationId,
            'name' => $provider->name,
            'sectors' => $this->codes['sectors'],
            'services' => $this->codes['services'],
            'documents' => $documentTypes,
        ], $this->ipAddress);
        MarketplaceNotifications::organizationRegistered('service_provider', $provider->id, $provider->name);

        return $member;
    }

    private function createMember(Role $role, Factory|ServiceProvider $organization): User
    {
        $member = new User(['name' => $this->account['name'], 'email' => $this->account['email']]);
        $member->password = Str::password(64);
        $member->role = $role;
        $member->factory_id = $organization instanceof Factory ? $organization->id : null;
        $member->service_provider_id = $organization instanceof ServiceProvider ? $organization->id : null;
        $member->save();

        return $member;
    }

    /**
     * @return list<string> the document types attached
     */
    private function attachDocuments(Factory|ServiceProvider $organization, User $member): array
    {
        $types = [];
        foreach ($this->documents as $document) {
            OrganizationDocument::recordStoredFile(
                $organization,
                DocumentType::from($document['type']),
                $document['disk'],
                $document['path'],
                $document['original_name'],
                $document['mime_type'],
                $document['size_bytes'],
                $document['sha256'],
                $member,
            );
            $types[] = $document['type'];
        }

        return $types;
    }

    /**
     * The first member created by this registration, when an earlier run of the job already
     * completed it.
     */
    private function memberOfCompletedRegistration(): ?User
    {
        $entry = AuditLog::query()
            ->whereIn('event', [AuditEvent::FactoryRegistered, AuditEvent::ServiceProviderRegistered])
            ->where('metadata->registration_id', $this->registrationId)
            ->first();

        return $entry?->actor_user_id === null ? null : User::query()->find($entry->actor_user_id);
    }

    /**
     * The account the email identifies: the stored address apart from letter case only,
     * as at login (the database collation alone would also ignore accents).
     */
    private function existingAccount(): ?User
    {
        $user = User::query()->where('email', $this->account['email'])->first();

        return $user !== null && Str::lower($user->email) === Str::lower($this->account['email']) ? $user : null;
    }

    private function discardDocuments(): void
    {
        foreach ($this->documents as $document) {
            Storage::disk($document['disk'])->delete($document['path']);
        }
    }
}
