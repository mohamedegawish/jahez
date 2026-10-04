<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\ProviderRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeclineProviderRequestRequest;
use App\Http\Requests\Api\V1\ListProviderRequestsRequest;
use App\Http\Requests\Api\V1\WithdrawProviderRequestRequest;
use App\Http\Resources\V1\ProviderRequestResource;
use App\Http\Resources\V1\ProviderRequestTransitionResource;
use App\Models\AuditLog;
use App\Models\ProviderRequest;
use App\Models\ProviderRequestMessage;
use App\Models\ProviderRequestRead;
use App\Models\ProviderRequestTransition;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A service request as sent to one provider, and the provider's answer (ADR-015). Every
 * status change locks the parent request and the thread, then checks the transition, so
 * repeated or concurrent actions get 409 instead of corrupting the state.
 */
class ProviderRequestController extends Controller
{
    private const RELATIONS = ['serviceProvider', 'agreement.review', 'agreement.activeContract', 'serviceRequest.industrialFactory', 'serviceRequest.service.category'];

    /**
     * A provider's inbox; a factory's sent requests per provider; everything for IMC.
     * Each thread carries its latest activity, latest offer version and, for the two
     * parties, the number of the other side's messages the caller has not read.
     */
    public function index(ListProviderRequestsRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        $side = self::sideOfMember($user);
        $search = $request->filled('search') ? '%'.addcslashes($request->string('search')->toString(), '%_\\').'%' : null;

        $threads = self::withActivity(ProviderRequest::query(), $user)
            ->when($user->service_provider_id !== null, fn (Builder $query) => $query->where('service_provider_id', $user->service_provider_id))
            ->when($user->factory_id !== null, fn (Builder $query) => $query->whereHas(
                'serviceRequest',
                fn (Builder $requests) => $requests->where('factory_id', $user->factory_id),
            ))
            ->when($request->input('filter.status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->input('filter.service_request'), fn (Builder $query, int|string $id) => $query->where('service_request_id', (int) $id))
            ->when($request->input('filter.service'), fn (Builder $query, string $code) => $query->whereHas(
                'serviceRequest.service',
                fn (Builder $services) => $services->where('code', $code),
            ))
            ->when($side !== null && $request->boolean('filter.unread'), fn (Builder $query) => $query->whereHas(
                'messages',
                fn (Builder $messages) => self::unreadBy($messages, $user, (string) $side),
            ))
            ->when($search, fn (Builder $query, string $search) => $query->where(fn (Builder $any) => $any
                ->whereHas('serviceRequest', fn (Builder $requests) => $requests
                    ->whereLike('title', $search)
                    ->orWhereHas('industrialFactory', fn (Builder $factories) => $factories->whereLike('name', $search)))
                ->orWhereHas('serviceProvider', fn (Builder $providers) => $providers->whereLike('name', $search))))
            ->with(self::RELATIONS)
            ->when(
                $request->sort() === 'recent_activity',
                fn (Builder $query) => $query->orderByDesc('status_changed_at')->orderByDesc('id'),
                fn (Builder $query) => $query->orderBy('id', $request->sort() === 'oldest' ? 'asc' : 'desc'),
            )
            ->paginate($request->perPage())
            ->withQueryString();

        return ProviderRequestResource::collection($threads);
    }

    public function show(ProviderRequest $providerRequest, #[CurrentUser] User $user): ProviderRequestResource
    {
        Gate::authorize('view', $providerRequest);

        return new ProviderRequestResource(
            self::withActivity(ProviderRequest::query(), $user)->with(self::RELATIONS)->findOrFail($providerRequest->id),
        );
    }

    /**
     * Mark the thread read up to its latest message, for the calling party only. Reading
     * the messages does not mark them, so a background refresh never hides new ones.
     */
    public function read(ProviderRequest $providerRequest, #[CurrentUser] User $user): ProviderRequestResource
    {
        Gate::authorize('negotiate', $providerRequest);

        $latest = (int) $providerRequest->messages()->max('id');
        if ($latest > 0) {
            ProviderRequestRead::markRead($providerRequest->id, $user, $latest);
        }

        return $this->show($providerRequest, $user);
    }

    /**
     * The side of the marketplace a member is on, or null for IMC administrators.
     */
    private static function sideOfMember(User $user): ?string
    {
        return match (true) {
            $user->service_provider_id !== null => ProviderRequestMessage::SIDE_PROVIDER,
            $user->factory_id !== null => ProviderRequestMessage::SIDE_FACTORY,
            default => null,
        };
    }

    /**
     * Adds `unread_messages_count` (parties only), `last_message_at` and
     * `latest_offer_version` to each thread.
     *
     * @param  Builder<ProviderRequest>  $query
     * @return Builder<ProviderRequest>
     */
    public static function withActivity(Builder $query, User $user): Builder
    {
        $side = self::sideOfMember($user);

        return $query
            ->when($side !== null, fn (Builder $threads) => $threads->withCount([
                'messages as unread_messages_count' => fn (Builder $messages) => self::unreadBy($messages, $user, (string) $side),
            ]))
            ->withMax('messages as last_message_at', 'created_at')
            ->withMax('offers as latest_offer_version', 'version');
    }

    /**
     * Messages of the other side after the user's read mark.
     *
     * @param  Builder<ProviderRequestMessage>  $messages
     * @return Builder<ProviderRequestMessage>
     */
    private static function unreadBy(Builder $messages, User $user, string $side): Builder
    {
        return $messages
            ->where('author_side', '!=', $side)
            ->whereRaw(
                'provider_request_messages.id > COALESCE((SELECT r.last_read_message_id FROM provider_request_reads r WHERE r.provider_request_id = provider_request_messages.provider_request_id AND r.user_id = ?), 0)',
                [$user->id],
            );
    }

    /**
     * The provider accepts to discuss the request. This opens the negotiation; it is not
     * an offer and not a contract. A provider IMC has suspended cannot accept (409).
     */
    public function accept(ProviderRequest $providerRequest, #[CurrentUser] User $user): ProviderRequestResource
    {
        Gate::authorize('respond', $providerRequest);

        return $this->transition($providerRequest, $user, ProviderRequestStatus::Accepted, AuditEvent::ProviderRequestAccepted);
    }

    /**
     * The provider declines, before or during the negotiation, even while suspended.
     */
    public function decline(DeclineProviderRequestRequest $request, ProviderRequest $providerRequest, #[CurrentUser] User $user): ProviderRequestResource
    {
        return $this->transition($providerRequest, $user, ProviderRequestStatus::Declined, AuditEvent::ProviderRequestDeclined, $request->reason());
    }

    /**
     * The factory withdraws the request from this provider, even while it is suspended.
     */
    public function withdraw(WithdrawProviderRequestRequest $request, ProviderRequest $providerRequest, #[CurrentUser] User $user): ProviderRequestResource
    {
        return $this->transition($providerRequest, $user, ProviderRequestStatus::Withdrawn, AuditEvent::ProviderRequestWithdrawn, $request->reason());
    }

    /**
     * The thread's status history, oldest first: who changed it, when and why. The two
     * parties and IMC administrators, who already see the status, may read it.
     */
    public function history(ProviderRequest $providerRequest): AnonymousResourceCollection
    {
        Gate::authorize('view', $providerRequest);

        $providerRequest->loadMissing('serviceRequest');

        return ProviderRequestTransitionResource::collection(
            $providerRequest->transitions()->with('actor')->get()->each(
                fn (ProviderRequestTransition $transition) => $transition->setRelation('providerRequest', $providerRequest),
            )
        );
    }

    private function transition(ProviderRequest $providerRequest, User $user, ProviderRequestStatus $next, AuditEvent $event, ?string $reason = null): ProviderRequestResource
    {
        DB::transaction(function () use ($providerRequest, $user, $next, $event, $reason): void {
            [, $locked] = ProviderRequest::lockWithServiceRequest($providerRequest->id);
            $previousStatus = $locked->status;

            if ($next === ProviderRequestStatus::Accepted && $previousStatus->canBecome($next)) {
                $locked->ensureProviderApproved();
            }

            $locked->moveTo($next, $reason, $user);
            MarketplaceNotifications::threadAnswered($locked, $next);

            AuditLog::record($event, $user, $locked, [
                'from' => $previousStatus->value,
                'to' => $next->value,
                'reason' => $reason,
            ]);
        });

        return $this->show($providerRequest->refresh(), $user);
    }
}
