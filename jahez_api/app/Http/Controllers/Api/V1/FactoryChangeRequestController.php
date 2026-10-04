<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\ProfileChangeRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListFactoryChangeRequestsRequest;
use App\Http\Requests\Api\V1\ListRequest;
use App\Http\Requests\Api\V1\StoreFactoryChangeRequestRequest;
use App\Http\Resources\V1\FactoryChangeRequestResource;
use App\Models\AuditLog;
use App\Models\Factory;
use App\Models\FactoryProfileChangeRequest;
use App\Models\OrganizationDocument;
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
 * Changes to a factory's recorded legal information (ADR-020), on the model of the
 * provider change requests (ADR-019). Once the legal name, a registration number or a
 * registration document holds a value, factory members change it only through a
 * request an IMC administrator approves (the values are applied) or rejects. Nothing
 * changes until the decision.
 *
 * Locks: the factory row first, then the request row, in every action.
 */
class FactoryChangeRequestController extends Controller
{
    private const RELATIONS = ['documents', 'requestedBy', 'reviewedBy', 'industrialFactory'];

    /**
     * The IMC review queue, oldest first; `filter[status]` defaults to pending.
     */
    public function queue(ListFactoryChangeRequestsRequest $request): AnonymousResourceCollection
    {
        return FactoryChangeRequestResource::collection(
            FactoryProfileChangeRequest::query()
                ->where('status', $request->status())
                ->with(self::RELATIONS)
                ->orderBy('id')
                ->paginate($request->perPage())
                ->withQueryString()
        );
    }

    /**
     * One factory's change requests, latest first: its members and IMC administrators.
     */
    public function index(ListRequest $request, Factory $factory): AnonymousResourceCollection
    {
        Gate::authorize('view', $factory);

        return FactoryChangeRequestResource::collection(
            $factory->changeRequests()
                ->with(self::RELATIONS)
                ->orderByDesc('id')
                ->paginate($request->perPage())
                ->withQueryString()
        );
    }

    public function store(StoreFactoryChangeRequestRequest $request, Factory $factory, #[CurrentUser] User $actor): JsonResponse
    {
        if (! (bool) config('jahez.factories.legal_changes_reviewed', true)) {
            throw new ConflictHttpException('Legal information is edited directly in the profile.');
        }

        $changes = $request->changedFields();
        $files = $request->documentFiles();
        $storedDocuments = [];

        try {
            $changeRequest = DB::transaction(function () use ($request, $factory, $actor, $changes, $files, &$storedDocuments): FactoryProfileChangeRequest {
                $locked = Factory::query()->lockForUpdate()->findOrFail($factory->id);

                if ($locked->openChangeRequest()->exists()) {
                    throw new ConflictHttpException('A change request is already waiting for IMC review. Cancel it or wait for the decision.');
                }

                $changeRequest = new FactoryProfileChangeRequest;
                $changeRequest->factory_id = $locked->id;
                $changeRequest->requested_by_user_id = $actor->id;
                $changeRequest->changes = $changes === [] ? null : $changes;
                $changeRequest->note = $request->filled('note') ? $request->string('note')->toString() : null;
                $changeRequest->save();

                foreach ($files as $field => $file) {
                    $document = OrganizationDocument::storeFor($locked, DocumentType::REQUEST_FIELDS[$field], $file, $actor, DocumentStatus::PendingReview);
                    $storedDocuments[] = $document;
                    $document->factory_profile_change_request_id = $changeRequest->id;
                    $document->save();
                }

                AuditLog::record(AuditEvent::FactoryChangeRequestSubmitted, $actor, $locked, [
                    'change_request_id' => $changeRequest->id,
                    'fields' => array_keys($changes),
                    'documents' => array_map(fn (OrganizationDocument $document): string => $document->type->value, $storedDocuments),
                ]);
                MarketplaceNotifications::changeRequestSubmitted('factory', $changeRequest->id, $locked->name);

                return $changeRequest;
            });
        } catch (Throwable $exception) {
            foreach ($storedDocuments as $document) {
                $document->deleteStoredFile();
            }

            throw $exception;
        }

        return (new FactoryChangeRequestResource($changeRequest->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /**
     * Apply the requested values and documents. Replaced documents are kept as superseded.
     */
    public function approve(Factory $factory, FactoryProfileChangeRequest $changeRequest, #[CurrentUser] User $actor): FactoryChangeRequestResource
    {
        Gate::authorize('reviewLegalChange', $factory);

        DB::transaction(function () use ($factory, $changeRequest, $actor): void {
            $locked = Factory::query()->lockForUpdate()->findOrFail($factory->id);
            $pending = $this->lockPending($changeRequest);

            $changes = $pending->changes ?? [];
            $locked->forceFill(array_intersect_key($changes, array_flip(Factory::LEGAL_FIELDS)))->save();

            $documentTypes = [];
            foreach ($pending->documents()->where('status', DocumentStatus::PendingReview)->get() as $document) {
                OrganizationDocument::supersedeActive($locked, $document->type);
                $document->status = DocumentStatus::Active;
                $document->save();
                $documentTypes[] = $document->type->value;
            }

            $pending->close(ProfileChangeRequestStatus::Approved, $actor);
            $pending->save();

            AuditLog::record(AuditEvent::FactoryChangeRequestApproved, $actor, $locked, [
                'change_request_id' => $pending->id,
                'fields' => array_keys($changes),
                'documents' => $documentTypes,
            ]);
            MarketplaceNotifications::changeRequestDecided('factory', $locked->id, $pending->id, approved: true);
        });

        return new FactoryChangeRequestResource($changeRequest->refresh()->load(self::RELATIONS));
    }

    public function reject(Request $request, Factory $factory, FactoryProfileChangeRequest $changeRequest, #[CurrentUser] User $actor): FactoryChangeRequestResource
    {
        Gate::authorize('reviewLegalChange', $factory);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];

        $this->close($factory, $changeRequest, ProfileChangeRequestStatus::Rejected, AuditEvent::FactoryChangeRequestRejected, $actor, $reason);

        return new FactoryChangeRequestResource($changeRequest->refresh()->load(self::RELATIONS));
    }

    public function cancel(Factory $factory, FactoryProfileChangeRequest $changeRequest, #[CurrentUser] User $actor): FactoryChangeRequestResource
    {
        Gate::authorize('requestLegalChange', $factory);

        $this->close($factory, $changeRequest, ProfileChangeRequestStatus::Cancelled, AuditEvent::FactoryChangeRequestCancelled, $actor);

        return new FactoryChangeRequestResource($changeRequest->refresh()->load(self::RELATIONS));
    }

    /**
     * Close a pending request without applying it. Its documents are kept as rejected.
     */
    private function close(Factory $factory, FactoryProfileChangeRequest $changeRequest, ProfileChangeRequestStatus $status, AuditEvent $event, User $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($factory, $changeRequest, $status, $event, $actor, $reason): void {
            $locked = Factory::query()->lockForUpdate()->findOrFail($factory->id);
            $pending = $this->lockPending($changeRequest);

            $pending->documents()->where('status', DocumentStatus::PendingReview)->update(['status' => DocumentStatus::Rejected, 'updated_at' => now()]);
            $pending->close($status, $status === ProfileChangeRequestStatus::Rejected ? $actor : null, $reason);
            $pending->save();

            AuditLog::record($event, $actor, $locked, [
                'change_request_id' => $pending->id,
                ...($reason !== null ? ['reason' => $reason] : []),
            ]);

            if ($status === ProfileChangeRequestStatus::Rejected) {
                MarketplaceNotifications::changeRequestDecided('factory', $locked->id, $pending->id, approved: false);
            }
        });
    }

    private function lockPending(FactoryProfileChangeRequest $changeRequest): FactoryProfileChangeRequest
    {
        $locked = FactoryProfileChangeRequest::query()->lockForUpdate()->findOrFail($changeRequest->id);

        if (! $locked->status->isOpen()) {
            throw new ConflictHttpException("This change request is already {$locked->status->value}.");
        }

        return $locked;
    }
}
