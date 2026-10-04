<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\DocumentType;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOrganizationDocumentRequest;
use App\Http\Resources\V1\OrganizationDocumentResource;
use App\Models\AuditLog;
use App\Models\Factory;
use App\Models\OrganizationDocument;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Logos and registration documents of factories and providers (ADR-019). Files are
 * stored on a private disk and read only through these endpoints, by the
 * organization's members and IMC administrators. A replaced file is kept as superseded.
 */
class OrganizationDocumentController extends Controller
{
    public const LEGAL_DOCUMENT_NEEDS_REVIEW = 'IMC has verified this provider\'s registration documents. Submit a change request to replace one.';

    public const FACTORY_LEGAL_DOCUMENT_NEEDS_REVIEW = 'This registration document is recorded. Submit a change request for IMC to review a replacement.';

    /**
     * A factory's members replace its logo directly and upload a missing registration
     * document directly; a recorded registration document is replaced only through a
     * change request (ADR-020).
     */
    public function storeForFactory(StoreOrganizationDocumentRequest $request, Factory $factory, #[CurrentUser] User $actor): JsonResponse
    {
        if ($request->documentType()->isLegal()
            && ! $actor->hasPermission(Permission::FactoriesUpdate)
            && $factory->legalDocumentIsRecorded($request->documentType())) {
            throw new ConflictHttpException(self::FACTORY_LEGAL_DOCUMENT_NEEDS_REVIEW);
        }

        return $this->store($request, $factory, $actor);
    }

    /**
     * A provider's members replace its logo directly. Its legal documents are replaced
     * directly only until IMC first approves the provider; afterwards they go through a
     * change request, so verified documents are never silently replaced.
     */
    public function storeForServiceProvider(StoreOrganizationDocumentRequest $request, ServiceProvider $serviceProvider, #[CurrentUser] User $actor): JsonResponse
    {
        if ($request->documentType()->isLegal()
            && $serviceProvider->hasVerifiedLegalInformation()
            && ! $actor->hasPermission(Permission::ServiceProvidersUpdate)) {
            throw new ConflictHttpException(self::LEGAL_DOCUMENT_NEEDS_REVIEW);
        }

        return $this->store($request, $serviceProvider, $actor);
    }

    public function showForFactory(Factory $factory, OrganizationDocument $document): StreamedResponse
    {
        Gate::authorize('view', $factory);

        return $document->toResponse();
    }

    public function showForServiceProvider(ServiceProvider $serviceProvider, OrganizationDocument $document): StreamedResponse
    {
        Gate::authorize('view', $serviceProvider);

        return $document->toResponse();
    }

    /**
     * The current logo of a provider listed in the directory, for those who may open its
     * directory profile (ProviderDirectoryController::show). Registration documents are
     * never served here.
     */
    public function directoryLogo(int $serviceProvider, #[CurrentUser] User $user): StreamedResponse
    {
        Gate::authorize('viewDirectory', ServiceProvider::class);

        $provider = ProviderDirectoryController::visibleTo($user)->whereKey($serviceProvider)->firstOrFail();

        return $provider->activeDocuments()->where('type', DocumentType::Logo)->firstOrFail()->toResponse();
    }

    private function store(StoreOrganizationDocumentRequest $request, Factory|ServiceProvider $organization, User $actor): JsonResponse
    {
        $type = $request->documentType();
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            abort(422, 'Send one file.');
        }
        $stored = null;

        try {
            $document = DB::transaction(function () use ($organization, $type, $file, $actor, &$stored): OrganizationDocument {
                $organization->newQuery()->whereKey($organization->id)->lockForUpdate()->first();
                $stored = OrganizationDocument::storeFor($organization, $type, $file, $actor);

                AuditLog::record(AuditEvent::OrganizationDocumentUploaded, $actor, $organization, [
                    'type' => $type->value,
                    'document_id' => $stored->id,
                ]);

                return $stored;
            });
        } catch (Throwable $exception) {
            $stored?->deleteStoredFile();

            throw $exception;
        }

        return (new OrganizationDocumentResource($document))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
