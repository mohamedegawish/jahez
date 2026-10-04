<?php

namespace App\Models;

use App\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use LogicException;

/**
 * One append-only audit entry (ADR-012). Entries are created only through record(),
 * never updated or deleted, and their metadata never holds passwords or tokens.
 *
 * @property AuditEvent $event
 * @property int|null $actor_user_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * Stable subject names stored instead of PHP class names.
     *
     * @var array<class-string<Model>, string>
     */
    public const SUBJECT_TYPES = [
        User::class => 'user',
        Factory::class => 'factory',
        ServiceProvider::class => 'service_provider',
        ServiceRequest::class => 'service_request',
        ProviderRequest::class => 'provider_request',
        Agreement::class => 'agreement',
        Contract::class => 'contract',
        Invoice::class => 'invoice',
        Payment::class => 'payment',
        ReadinessQuestionnaire::class => 'readiness_questionnaire',
        ServicePromotion::class => 'service_promotion',
        PublicAnnouncement::class => 'public_announcement',
        FinancialPolicy::class => 'financial_policy',
        FinancialPolicyVersion::class => 'financial_policy_version',
    ];

    /**
     * Metadata keys removed before storage, at any depth, as a guard against secrets.
     */
    private const FORBIDDEN_METADATA_KEY_PATTERN = '/password|token|secret/i';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AuditEvent::class,
            'actor_user_id' => 'integer',
            'subject_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Audit log entries are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Audit log entries are immutable.'));
    }

    /**
     * Record an action, with the IP address and request ID of the current request.
     * Queued jobs have no request, so they pass the IP address they were dispatched with.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function record(AuditEvent $event, ?User $actor = null, User|Factory|ServiceProvider|ServiceRequest|ProviderRequest|Agreement|Contract|Invoice|Payment|ReadinessQuestionnaire|ServicePromotion|PublicAnnouncement|FinancialPolicy|FinancialPolicyVersion|null $subject = null, array $metadata = [], ?string $ipAddress = null): self
    {
        $entry = new self;
        $entry->event = $event;
        $entry->actor_user_id = $actor?->id;
        $entry->subject_type = $subject !== null ? self::SUBJECT_TYPES[$subject::class] : null;
        $entry->subject_id = $subject?->getKey();
        $entry->ip_address = $ipAddress ?? (app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->ip());
        $entry->request_id = Context::get('request_id');
        $entry->metadata = $metadata === [] ? null : self::withoutSecrets($metadata);
        $entry->save();

        return $entry;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array<array-key, mixed>
     */
    private static function withoutSecrets(array $metadata): array
    {
        $clean = [];

        foreach ($metadata as $key => $value) {
            if (is_string($key) && preg_match(self::FORBIDDEN_METADATA_KEY_PATTERN, $key) === 1) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::withoutSecrets($value) : $value;
        }

        return $clean;
    }
}
