<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\ProfileChangeRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListProviderChangeRequestsRequest;
use App\Http\Requests\Api\V1\ListRequest;
use App\Http\Requests\Api\V1\StoreProviderChangeRequestRequest;
use App\Http\Resources\V1\ProviderChangeRequestResource;
use App\Models\AuditLog;
use App\Models\OrganizationDocument;
use App\Models\ProviderProfileChangeRequest;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Changes to a provider's verified legal information (ADR-019). After IMC has approved a
 * provider, its members cannot replace the legal name, registration numbers or
 * registration documents directly; they submit a change request, and an IMC
 * administrator approves (the values are applied) or rejects it. The provider stays
 * approved either way, and nothing changes until the decision.
 *
 * Locks: the provider row first, then the request row, in every action.
 */
class ProviderChangeRequestController extends Controller
{
    private const RELATIONS = ['documents', 'requestedBy', 'reviewedBy', 'serviceProvider'];

    /**
     * The IMC review queue, oldest first; `filter[status]` defaults to pending.
     */
    public function queue(ListProviderChangeRequestsRequest $request): AnonymousResourceCollection
    {
        return ProviderChangeRequestResource::collection(
            ProviderProfileChangeRequest::query()
                ->where('status', $request->status())
                ->with(self::RELATIONS)
                ->orderBy('id')
                ->paginate($request->perPage())
                ->withQueryString()
        );
    }

    /**
     * One provider's change requests, latest first: its members and IMC administrators.
     */
    public function index(ListRequest $request, ServiceProvider $serviceProvider): AnonymousResourceCollection
    {
        Gate::authorize('view', $serviceProvider);

        return ProviderChangeRequestResource::collection(
            $serviceProvider->changeRequests()
                ->with(self::RELATIONS)
                ->orderByDesc('id')
                ->paginate($request->perPage())
                ->withQueryString()
        );
    }

    public function store(StoreProviderChangeRequestRequest $request, ServiceProvider $serviceProvider, #[CurrentUser] User $actor): JsonResponse
    {
        $changes = $request->changedFields();
        $files = $request->documentFiles();
        $storedDocuments = [];

        try {
            $changeRequest = DB::transaction(function () use ($request, $serviceProvider, $actor, $changes, $files, &$storedDocuments): ProviderProfileChangeRequest {
                $provider = ServiceProvider::query()->lockForUpdate()->findOrFail($serviceProvider->id);

                if (! $provider->hasVerifiedLegalInformation()) {
                    throw new ConflictHttpException('IMC has not approved this provider yet: edit the legal information in the profile directly.');
                }

                if ($provider->openChangeRequest()->exists()) {
                    throw new ConflictHttpException('A change request is already waiting for IMC review. Cancel it or wait for the decision.');
                }

                $changeRequest = new ProviderProfileChangeRequest;
                $changeRequest->service_provider_id = $provider->id;
                $changeRequest->requested_by_user_id = $actor->id;
                $changeRequest->changes = $changes === [] ? null : $changes;
                $changeRequest->note = $request->filled('note') ? $request->string('note')->toString() : null;
                $changeRequest->save();

                foreach ($files as $field => $file) {
                    $document = OrganizationDocument::storeFor($provider, DocumentType::REQUEST_FIELDS[$field], $file, $actor, DocumentStatus::PendingReview);
                    $storedDocuments[] = $document;
                    $document->provider_profile_change_request_id = $changeRequest->id;
                    $document->save();
                }

                AuditLog::record(AuditEvent::ProviderChangeRequestSubmitted, $actor, $provider, [
                    'change_request_id' => $changeRequest->id,
                    'fields' => array_keys($changes),
                    'documents' => array_map(fn (OrganizationDocument $document): string => $document->type->value, $storedDocuments),
                ]);
                MarketplaceNotifications::changeRequestSubmitted('provider', $changeRequest->id, $provider->name);

                return $changeRequest;
            });
        } catch (Throwable $exception) {
            foreach ($storedDocuments as $document) {
                $document->deleteStoredFile();
            }

            throw $exception;
        }

        return (new ProviderChangeRequestResource($changeRequest->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /**
     * Apply the requested values and documents. Replaced documents are kept as superseded.
     */
    public function approve(ServiceProvider $serviceProvider, ProviderProfileChangeRequest $changeRequest, #[CurrentUser] User $actor): ProviderChangeRequestResource
    {
        Gate::authorize('approve', $serviceProvider);

        DB::transaction(function () use ($serviceProvider, $changeRequest, $actor): void {
            $provider = ServiceProvider::query()->lockForUpdate()->findOrFail($serviceProvider->id);
            $locked = $this->lockPending($changeRequest);

            $changes = $locked->changes ?? [];
            $provider->forceFill(array_intersect_key($changes, array_flip(ServiceProvider::LEGAL_FIELDS)))->save();

            $documentTypes = [];
            foreach ($locked->documents()->where('status', DocumentStatus::PendingReview)->get() as $document) {
                OrganizationDocument::supersedeActive($provider, $document->type);
                $document->status = DocumentStatus::Active;
                $document->save();
                $documentTypes[] = $document->type->value;
            }

            $locked->close(ProfileChangeRequestStatus::Approved, $actor);
            $locked->save();

            AuditLog::record(AuditEvent::ProviderChangeRequestApproved, $actor, $provider, [
                'change_request_id' => $locked->id,
                'fields' => array_keys($changes),
                'documents' => $documentTypes,
            ]);
            MarketplaceNotifications::changeRequestDecided('provider', $provider->id, $locked->id, approved: true);
        });

        return new ProviderChangeRequestResource($changeRequest->refresh()->load(self::RELATIONS));
    }

    public function reject(Request $request, ServiceProvider $serviceProvider, ProviderProfileChangeRequest $changeRequest, #[CurrentUser] User $actor): ProviderChangeRequestResource
    {
        Gate::authorize('approve', $serviceProvider);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];

        $this->close($serviceProvider, $changeRequest, ProfileChangeRequestStatus::Rejected, AuditEvent::ProviderChangeRequestRejected, $actor, $reason);

        return new ProviderChangeRequestResource($changeRequest->refresh()->load(self::RELATIONS));
    }

    public function cancel(ServiceProvider $serviceProvider, ProviderProfileChangeRequest $changeRequest, #[CurrentUser] User $actor): ProviderChangeRequestResource
    {
        Gate::authorize('requestLegalChange', $serviceProvider);

        $this->close($serviceProvider, $changeRequest, ProfileChangeRequestStatus::Cancelled, AuditEvent::ProviderChangeRequestCancelled, $actor);

        return new ProviderChangeRequestResource($changeRequest->refresh()->load(self::RELATIONS));
    }

    /**
     * Close a pending request without applying it. Its documents are kept as rejected.
     */
    private function close(ServiceProvider $serviceProvider, ProviderProfileChangeRequest $changeRequest, ProfileChangeRequestStatus $status, AuditEvent $event, User $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($serviceProvider, $changeRequest, $status, $event, $actor, $reason): void {
            $provider = ServiceProvider::query()->lockForUpdate()->findOrFail($serviceProvider->id);
            $locked = $this->lockPending($changeRequest);

            $locked->documents()->where('status', DocumentStatus::PendingReview)->update(['status' => DocumentStatus::Rejected, 'updated_at' => now()]);
            $locked->close($status, $status === ProfileChangeRequestStatus::Rejected ? $actor : null, $reason);
            $locked->save();

            AuditLog::record($event, $actor, $provider, [
                'change_request_id' => $locked->id,
                ...($reason !== null ? ['reason' => $reason] : []),
            ]);

            if ($status === ProfileChangeRequestStatus::Rejected) {
                MarketplaceNotifications::changeRequestDecided('provider', $provider->id, $locked->id, approved: false);
            }
        });
    }

    private function lockPending(ProviderProfileChangeRequest $changeRequest): ProviderProfileChangeRequest
    {
        $locked = ProviderProfileChangeRequest::query()->lockForUpdate()->findOrFail($changeRequest->id);

        if (! $locked->status->isOpen()) {
            throw new ConflictHttpException("This change request is already {$locked->status->value}.");
        }

        return $locked;
    }
}
