<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\ProviderRequestStatus;
use App\Enums\ServiceRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddServiceRequestProvidersRequest;
use App\Http\Requests\Api\V1\CancelServiceRequestRequest;
use App\Http\Requests\Api\V1\ListServiceRequestsRequest;
use App\Http\Requests\Api\V1\StoreServiceRequestRequest;
use App\Http\Resources\V1\ServiceRequestResource;
use App\Models\AuditLog;
use App\Models\Factory;
use App\Models\ProviderRequest;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Factory service requests of the marketplace (ADR-015; workflow in docs/workflows.md).
 */
class ServiceRequestController extends Controller
{
    /**
     * A factory member sees their factory's requests; an IMC administrator sees all.
     */
    public function index(ListServiceRequestsRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        $search = $request->filled('search') ? '%'.addcslashes($request->string('search')->toString(), '%_\\').'%' : null;

        $serviceRequests = ServiceRequest::query()
            ->when($user->factory_id !== null, fn (Builder $query) => $query->where('factory_id', $user->factory_id))
            ->when($request->input('filter.status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->input('filter.service'), fn (Builder $query, string $code) => $query->whereHas('service', fn (Builder $services) => $services->where('code', $code)))
            ->when($request->input('filter.thread_status'), fn (Builder $query, string $status) => $query->whereHas('providerRequests', fn (Builder $threads) => $threads->where('status', $status)))
            ->when($search, fn (Builder $query, string $search) => $query->where(fn (Builder $any) => $any
                ->whereLike('title', $search)
                ->orWhereHas('providerRequests.serviceProvider', fn (Builder $providers) => $providers->whereLike('name', $search))))
            ->with(['industrialFactory', 'service.category'])
            ->orderBy('id', $request->string('sort')->toString() === 'oldest' ? 'asc' : 'desc')
            ->paginate($request->perPage())
            ->withQueryString();

        $serviceRequests->getCollection()->load([
            'providerRequests' => fn (Relation $threads) => $this->threadsWithActivity($threads, $user),
        ]);

        return ServiceRequestResource::collection($serviceRequests);
    }

    /**
     * Each thread with its provider, its agreement's review and contract statuses, and
     * its activity (ProviderRequestController::withActivity).
     *
     * @param  Relation<ProviderRequest, ServiceRequest, mixed>  $threads
     * @return Relation<ProviderRequest, ServiceRequest, mixed>
     */
    private function threadsWithActivity(Relation $threads, User $user): Relation
    {
        ProviderRequestController::withActivity($threads->getQuery(), $user);

        return $threads->with(['serviceProvider', 'agreement.review', 'agreement.activeContract'])->orderBy('id');
    }

    /**
     * Create the request and one provider request per chosen provider, all pending.
     * Eligibility is checked again inside the transaction, with the provider rows
     * share-locked, so a provider IMC suspends after validation is still refused.
     */
    public function store(StoreServiceRequestRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $service = $request->service();
        $providerIds = $request->providerIds();

        $serviceRequest = DB::transaction(function () use ($request, $user, $service, $providerIds): ServiceRequest {
            $this->ensureFactoryMaySendRequests((int) $user->factory_id);

            if ($request->eligibleProviders()->sharedLock()->count() !== count($providerIds)) {
                throw ValidationException::withMessages(['provider_ids' => StoreServiceRequestRequest::INELIGIBLE_PROVIDERS]);
            }

            $serviceRequest = new ServiceRequest;
            $serviceRequest->factory_id = (int) $user->factory_id;
            $serviceRequest->catalog_service_id = $service->id;
            $serviceRequest->title = $request->string('title')->toString();
            $serviceRequest->need = $request->string('need')->toString();
            $serviceRequest->requirements = $request->filled('requirements') ? $request->string('requirements')->toString() : null;
            $serviceRequest->status = ServiceRequestStatus::Open;
            $serviceRequest->status_changed_at = now();
            $serviceRequest->created_by_user_id = $user->id;
            $serviceRequest->save();

            $this->openThreads($serviceRequest, $providerIds, $user);

            sort($providerIds);
            AuditLog::record(AuditEvent::ServiceRequestCreated, $user, $serviceRequest, [
                'service' => $service->code,
                'provider_ids' => $providerIds,
            ]);

            return $serviceRequest;
        });

        return (new ServiceRequestResource($serviceRequest->load(['industrialFactory', 'service.category', 'providerRequests.serviceProvider'])))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /**
     * The requesting factory and IMC see every provider thread; a provider sees only its
     * own, so it never learns which competitors received the same request.
     */
    public function show(ServiceRequest $serviceRequest, #[CurrentUser] User $user): ServiceRequestResource
    {
        Gate::authorize('view', $serviceRequest);

        $isProvider = $user->service_provider_id !== null;

        return new ServiceRequestResource($serviceRequest->load([
            'industrialFactory',
            'service.category',
            'providerRequests' => fn (Relation $threads) => $this->threadsWithActivity($threads, $user)
                ->when($isProvider, fn ($query) => $query->where('service_provider_id', $user->service_provider_id)),
        ]));
    }

    /**
     * Cancel an open request. Every thread that is still pending or negotiating is closed.
     */
    public function cancel(CancelServiceRequestRequest $request, ServiceRequest $serviceRequest, #[CurrentUser] User $user): ServiceRequestResource
    {
        DB::transaction(function () use ($request, $serviceRequest, $user): void {
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($serviceRequest->id);

            if (! $locked->isOpen()) {
                throw new ConflictHttpException("This service request is {$locked->status->value} and cannot be cancelled.");
            }

            $locked->moveTo(ServiceRequestStatus::Cancelled);

            $closedIds = ProviderRequest::closeOpenThreadsOf($locked->id, 'request_cancelled', actor: $user);
            MarketplaceNotifications::threadsClosed($locked, $closedIds, 'request_cancelled');

            AuditLog::record(AuditEvent::ServiceRequestCancelled, $user, $locked, [
                'reason' => $request->reason(),
                'closed_provider_request_ids' => $closedIds,
            ]);
        });

        return $this->show($serviceRequest->refresh(), $user);
    }

    /**
     * Send an open request to more eligible providers, one new pending thread each
     * (PROPOSED, OQ-38): the way out when every provider has declined. A provider that
     * already received the request, in any status, cannot receive it again, and a
     * request has at most the technical maximum of providers.
     */
    public function addProviders(AddServiceRequestProvidersRequest $request, ServiceRequest $serviceRequest, #[CurrentUser] User $user): ServiceRequestResource
    {
        $providerIds = $request->providerIds();

        DB::transaction(function () use ($request, $serviceRequest, $user, $providerIds): void {
            $locked = ServiceRequest::query()->lockForUpdate()->findOrFail($serviceRequest->id);

            if (! $locked->isOpen()) {
                throw new ConflictHttpException("This service request is {$locked->status->value}; providers can be added only to an open request.");
            }

            $this->ensureFactoryMaySendRequests($locked->factory_id);

            if ($locked->providerRequests()->whereIn('service_provider_id', $providerIds)->exists()) {
                throw ValidationException::withMessages(['provider_ids' => 'A provider in the list has already received this request.']);
            }

            if ($locked->providerRequests()->count() + count($providerIds) > StoreServiceRequestRequest::MAX_PROVIDERS) {
                throw ValidationException::withMessages(['provider_ids' => 'A request can be sent to at most '.StoreServiceRequestRequest::MAX_PROVIDERS.' providers.']);
            }

            if ($request->eligibleProviders()->sharedLock()->count() !== count($providerIds)) {
                throw ValidationException::withMessages(['provider_ids' => StoreServiceRequestRequest::INELIGIBLE_PROVIDERS]);
            }

            $this->openThreads($locked, $providerIds, $user);

            sort($providerIds);
            AuditLog::record(AuditEvent::ServiceRequestProvidersAdded, $user, $locked, ['provider_ids' => $providerIds]);
        });

        return $this->show($serviceRequest->refresh(), $user);
    }

    /**
     * Only an approved factory sends requests while the approval gate is on (ADR-021,
     * PROPOSED OQ-46). The factory row is share-locked, so a suspension decided at the
     * same moment is applied before or after the whole request, never in between.
     */
    private function ensureFactoryMaySendRequests(int $factoryId): void
    {
        $factory = Factory::query()->sharedLock()->findOrFail($factoryId);

        if (! $factory->maySendServiceRequests()) {
            throw new ConflictHttpException("Your factory's account is {$factory->approval_status->value}: service requests can be sent once IMC approves it.");
        }
    }

    /**
     * One pending thread per provider, each starting its history.
     *
     * @param  list<int>  $providerIds
     */
    private function openThreads(ServiceRequest $serviceRequest, array $providerIds, User $user): void
    {
        $threads = [];
        foreach ($providerIds as $providerId) {
            $providerRequest = new ProviderRequest;
            $providerRequest->service_request_id = $serviceRequest->id;
            $providerRequest->service_provider_id = $providerId;
            $providerRequest->save();
            $providerRequest->recordTransition(null, ProviderRequestStatus::Pending, null, $user);
            $threads[] = $providerRequest;
        }

        MarketplaceNotifications::requestSent($serviceRequest, $threads);
    }
}
