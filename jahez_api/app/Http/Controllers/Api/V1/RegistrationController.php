<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterFactoryRequest;
use App\Http\Requests\Api\V1\RegisterServiceProviderRequest;
use App\Http\Resources\V1\SectorResource;
use App\Http\Resources\V1\ServiceCategoryResource;
use App\Jobs\RegisterOrganization;
use App\Models\Sector;
use App\Models\ServiceCategory;
use App\Models\ServiceProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Public self-registration of factories and service providers (ADR-019).
 *
 * A registration is validated here and completed in the RegisterOrganization job. The
 * response is the same 202 whether or not the email already has an account, and the
 * request does the same work either way (validate, store the uploads, queue the job), so
 * neither the response nor its timing reveals which emails are registered (ADR-011). The
 * new member sets a password through the emailed link, which also proves they own the
 * address; until then nobody can sign in to the account.
 */
class RegistrationController extends Controller
{
    public const RESPONSE_MESSAGE = 'Thank you. If the details can be registered, an email with a link to set your password will arrive shortly. Use the link to sign in.';

    /**
     * What the public registration forms need: the sectors, the factory sizes and the
     * service catalog. The same reference data the signed-in endpoints return, read-only.
     */
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'sectors' => SectorResource::collection(Sector::query()->orderBy('sort_order')->get()),
            'factory_sizes' => array_map(
                fn (string $code, string $name): array => ['code' => $code, 'name_ar' => $name],
                array_keys((array) config('jahez.factories.sizes')),
                array_values((array) config('jahez.factories.sizes')),
            ),
            'service_categories' => ServiceCategoryResource::collection(ServiceCategory::query()->with('services')->orderBy('sort_order')->get()),
            'documents' => [
                'logo' => ['extensions' => DocumentType::Logo->allowedExtensions(), 'max_kb' => DocumentType::Logo->maxKilobytes()],
                'legal' => ['extensions' => DocumentType::CommercialRegistration->allowedExtensions(), 'max_kb' => DocumentType::CommercialRegistration->maxKilobytes()],
            ],
        ]]);
    }

    public function factory(RegisterFactoryRequest $request): JsonResponse
    {
        $this->stageAndDispatch($request, fn (array $documents) => RegisterOrganization::dispatch(
            (string) Str::uuid(),
            RegisterOrganization::FACTORY,
            $request->safe()->only(['name', 'legal_name', 'size', 'contact_name', 'contact_job_title', 'contact_email', 'contact_phone', 'website', 'governorate', 'city', 'address', 'commercial_registration_number', 'tax_registration_number']),
            ['sectors' => $request->validated('sectors', []), 'services' => []],
            ['name' => $request->string('contact_name')->toString(), 'email' => $request->string('contact_email')->toString()],
            $documents,
            $request->ip(),
        ));

        return $this->accepted();
    }

    public function serviceProvider(RegisterServiceProviderRequest $request): JsonResponse
    {
        $this->stageAndDispatch($request, fn (array $documents) => RegisterOrganization::dispatch(
            (string) Str::uuid(),
            RegisterOrganization::SERVICE_PROVIDER,
            $request->safe()->only([...ServiceProvider::PROFILE_FIELDS, ...ServiceProvider::LEGAL_FIELDS]),
            ['sectors' => $request->validated('sectors', []), 'services' => $request->validated('services', [])],
            ['name' => $request->string('representative_name')->toString(), 'email' => $request->string('email')->toString()],
            $documents,
            $request->ip(),
        ));

        return $this->accepted();
    }

    /**
     * Stage the uploads, then queue the registration. If storing a file or queuing the
     * job fails, the files staged so far are deleted, so no orphan upload stays behind.
     *
     * @param  callable(list<array{type: string, disk: string, path: string, original_name: string, mime_type: string, size_bytes: int, sha256: string}>): mixed  $dispatch
     */
    private function stageAndDispatch(FormRequest $request, callable $dispatch): void
    {
        $disk = (string) config('jahez.documents.disk');
        $folder = 'documents/registrations/'.Str::uuid()->toString();

        try {
            $documents = $this->stageDocuments($request, $disk, $folder);
            // The pending dispatch is queued when it is destroyed, at the end of this statement.
            $dispatch($documents);
        } catch (Throwable $exception) {
            Storage::disk($disk)->deleteDirectory($folder);

            throw $exception;
        }
    }

    /**
     * Store the uploads on the private documents disk under random names. The job turns
     * them into the organization's documents, or deletes them when nothing is created.
     *
     * @return list<array{type: string, disk: string, path: string, original_name: string, mime_type: string, size_bytes: int, sha256: string}>
     */
    private function stageDocuments(FormRequest $request, string $disk, string $folder): array
    {
        $staged = [];

        foreach (DocumentType::REQUEST_FIELDS as $field => $type) {
            $file = $request->file($field);
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $extension = strtolower($file->guessExtension() ?? $file->extension());
            $path = Storage::disk($disk)->putFileAs($folder, $file, Str::uuid()->toString().'.'.$extension);
            if ($path === false) {
                throw new RuntimeException('The uploaded file could not be stored.');
            }

            $staged[] = [
                'type' => $type->value,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => (string) $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(),
                'sha256' => (string) hash_file('sha256', (string) $file->getRealPath()),
            ];
        }

        return $staged;
    }

    private function accepted(): JsonResponse
    {
        return response()->json(['data' => ['message' => self::RESPONSE_MESSAGE]], Response::HTTP_ACCEPTED);
    }
}
