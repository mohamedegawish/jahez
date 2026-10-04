<?php

namespace App\Models;

use App\Enums\ProviderApprovalStatus;
use App\Enums\ProviderRequestStatus;
use Database\Factories\ProviderRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A service request as sent to one provider, and the private thread between that
 * provider and the factory (ADR-015). Status changes only through moveTo(), which checks
 * the transition.
 *
 * @property int $id
 * @property int $service_request_id
 * @property int $service_provider_id
 * @property ProviderRequestStatus $status
 * @property string|null $status_reason
 * @property Carbon|null $status_changed_at
 * @property int|null $agreed_offer_id
 */
class ProviderRequest extends Model
{
    /** @use HasFactory<ProviderRequestFactory> */
    use HasFactory;

    public const PAUSED_MESSAGE = 'This provider is not approved by IMC at the moment, so this negotiation is paused.';

    /**
     * Mirrors the column defaults so a model that was just created can be read without
     * reloading it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'status_reason' => null,
        'status_changed_at' => null,
        'agreed_offer_id' => null,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_request_id' => 'integer',
            'service_provider_id' => 'integer',
            'agreed_offer_id' => 'integer',
            'status' => ProviderRequestStatus::class,
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ServiceRequest, $this>
     */
    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    /**
     * @return BelongsTo<ServiceProvider, $this>
     */
    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class);
    }

    /**
     * @return HasMany<ProviderRequestMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ProviderRequestMessage::class);
    }

    /**
     * @return HasMany<Offer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * The agreement, once the factory accepted this provider's offer (ADR-017).
     *
     * @return HasOne<Agreement, $this>
     */
    public function agreement(): HasOne
    {
        return $this->hasOne(Agreement::class);
    }

    /**
     * The status history, oldest first.
     *
     * @return HasMany<ProviderRequestTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(ProviderRequestTransition::class)->orderBy('id');
    }

    /**
     * @return HasOne<Offer, $this>
     */
    public function latestOffer(): HasOne
    {
        return $this->hasOne(Offer::class)->latestOfMany('version');
    }

    /**
     * Locks the parent service request and then this provider request, always in that
     * order, so concurrent actions on the same request queue instead of deadlocking, and
     * each action sees the statuses the previous one left. Call inside a transaction.
     *
     * @return array{ServiceRequest, ProviderRequest}
     */
    public static function lockWithServiceRequest(int $providerRequestId): array
    {
        $serviceRequestId = (int) self::query()->whereKey($providerRequestId)->value('service_request_id');
        $serviceRequest = ServiceRequest::query()->lockForUpdate()->findOrFail($serviceRequestId);
        $providerRequest = self::query()->lockForUpdate()->findOrFail($providerRequestId);

        return [$serviceRequest, $providerRequest];
    }

    /**
     * Locks only this provider request. For actions that change nothing on the parent
     * request (messages and offer versions); every action that changes the parent locks it
     * first through lockWithServiceRequest(), so the lock order stays request, then
     * thread. Call inside a transaction.
     */
    public static function lockThread(int $providerRequestId): self
    {
        return self::query()->lockForUpdate()->findOrFail($providerRequestId);
    }

    /**
     * Refuses with 409 while IMC has not approved the provider, which happens when IMC
     * suspends it after the request was sent. The thread is not closed, so it resumes once
     * IMC approves the provider again (PROPOSED, OQ-40). The provider row is share-locked
     * until the transaction ends, so an approval decision cannot change it meanwhile.
     */
    public function ensureProviderApproved(): void
    {
        $approval = ServiceProvider::query()->whereKey($this->service_provider_id)->sharedLock()->value('approval_status');

        if ($approval !== ProviderApprovalStatus::Approved) {
            throw new ConflictHttpException(self::PAUSED_MESSAGE);
        }
    }

    /**
     * The side of this thread the user is on: a member of the requesting factory, a member
     * of the provider it was sent to, or neither (null). IMC administrators are on neither
     * side.
     */
    public function sideOf(User $user): ?string
    {
        return match (true) {
            $user->belongsToServiceProvider($this->service_provider_id) => ProviderRequestMessage::SIDE_PROVIDER,
            $this->serviceRequest !== null && $user->belongsToFactory($this->serviceRequest->factory_id) => ProviderRequestMessage::SIDE_FACTORY,
            default => null,
        };
    }

    /**
     * Closes every thread of the service request that is not yet final, except the given
     * one, and returns their ids. Call inside the transaction that locked the service
     * request; the threads are locked in id order.
     *
     * @return list<int>
     */
    public static function closeOpenThreadsOf(int $serviceRequestId, string $reason, ?int $exceptId = null, ?User $actor = null): array
    {
        $closedIds = [];
        $threads = self::query()
            ->where('service_request_id', $serviceRequestId)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($threads as $thread) {
            if (! $thread->status->isTerminal()) {
                $thread->moveTo(ProviderRequestStatus::Closed, $reason, $actor);
                $closedIds[] = $thread->id;
            }
        }

        return $closedIds;
    }

    /**
     * Apply a status change the workflow allows, or refuse it with 409, and add it to the
     * thread's history.
     */
    public function moveTo(ProviderRequestStatus $next, ?string $reason = null, ?User $actor = null): void
    {
        if (! $this->status->canBecome($next)) {
            throw new ConflictHttpException("This provider request is {$this->status->value} and cannot become {$next->value}.");
        }

        $previous = $this->status;
        $this->status = $next;
        $this->status_reason = $reason;
        $this->status_changed_at = now();
        $this->save();

        $this->recordTransition($previous, $next, $reason, $actor);
    }

    /**
     * Add a status change to the thread's history; `$from` is null for the creation.
     */
    public function recordTransition(?ProviderRequestStatus $from, ProviderRequestStatus $to, ?string $reason, ?User $actor): void
    {
        $transition = new ProviderRequestTransition;
        $transition->provider_request_id = $this->id;
        $transition->from_status = $from;
        $transition->to_status = $to;
        $transition->reason = $reason;
        $transition->actor_user_id = $actor?->id;
        $transition->save();
    }
}
