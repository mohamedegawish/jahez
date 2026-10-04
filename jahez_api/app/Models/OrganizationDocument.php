<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * A file an organization uploaded (ADR-019): its logo or a registration document. The
 * file sits on the private documents disk under a random name; the client's file name is
 * kept only as metadata. Files are served by authorized endpoints, never by public URL.
 *
 * @property int $id
 * @property int|null $factory_id
 * @property int|null $service_provider_id
 * @property DocumentType $type
 * @property DocumentStatus $status
 * @property int|null $provider_profile_change_request_id
 * @property int|null $factory_profile_change_request_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property int|null $uploaded_by_user_id
 * @property Carbon|null $created_at
 */
class OrganizationDocument extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'factory_id' => 'integer',
            'service_provider_id' => 'integer',
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'provider_profile_change_request_id' => 'integer',
            'factory_profile_change_request_id' => 'integer',
            'size_bytes' => 'integer',
            'uploaded_by_user_id' => 'integer',
        ];
    }

    /**
     * Store an uploaded file for the organization on the documents disk and record it.
     * The caller runs this inside its transaction and deletes the file if the
     * transaction fails (deleteStoredFile()).
     */
    public static function storeFor(Factory|ServiceProvider $owner, DocumentType $type, UploadedFile $file, ?User $uploader, DocumentStatus $status = DocumentStatus::Active): self
    {
        $disk = (string) config('jahez.documents.disk');
        $extension = strtolower($file->guessExtension() ?? $file->extension());
        $folder = ($owner instanceof Factory ? 'factories/' : 'service-providers/').$owner->id;
        $path = Storage::disk($disk)->putFileAs("documents/{$folder}", $file, Str::uuid()->toString().'.'.$extension);

        if ($path === false) {
            throw new RuntimeException('The uploaded file could not be stored.');
        }

        try {
            return self::recordStoredFile($owner, $type, $disk, $path, $file->getClientOriginalName(), (string) $file->getMimeType(), (int) $file->getSize(), (string) hash_file('sha256', (string) $file->getRealPath()), $uploader, $status);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    /**
     * Record a file that is already on the documents disk (a registration's staged
     * upload) as the organization's document.
     */
    public static function recordStoredFile(Factory|ServiceProvider $owner, DocumentType $type, string $disk, string $path, string $originalName, string $mimeType, int $sizeBytes, string $sha256, ?User $uploader, DocumentStatus $status = DocumentStatus::Active): self
    {
        $document = new self;
        $document->factory_id = $owner instanceof Factory ? $owner->id : null;
        $document->service_provider_id = $owner instanceof ServiceProvider ? $owner->id : null;
        $document->type = $type;
        $document->status = $status;
        $document->disk = $disk;
        $document->path = $path;
        $document->original_name = Str::limit(self::cleanFileName($originalName), 250, '');
        $document->mime_type = $mimeType;
        $document->size_bytes = $sizeBytes;
        $document->sha256 = $sha256;
        $document->uploaded_by_user_id = $uploader?->id;

        if ($status === DocumentStatus::Active) {
            self::supersedeActive($owner, $type);
        }

        $document->save();

        return $document;
    }

    /**
     * Mark the organization's current file of this type as superseded. The file is kept.
     */
    public static function supersedeActive(Factory|ServiceProvider $owner, DocumentType $type): void
    {
        self::query()
            ->where($owner instanceof Factory ? 'factory_id' : 'service_provider_id', $owner->id)
            ->where('type', $type)
            ->where('status', DocumentStatus::Active)
            ->update(['status' => DocumentStatus::Superseded, 'updated_at' => now()]);
    }

    /**
     * A display name without path separators or control characters.
     */
    public static function cleanFileName(string $name): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', $name));

        return $clean === '' ? 'document' : $clean;
    }

    /**
     * Remove the stored file, for a write that failed after the file was stored.
     */
    public function deleteStoredFile(): void
    {
        Storage::disk($this->disk)->delete($this->path);
    }

    /**
     * The file as a download (legal documents) or inline (a logo, so an image tag can
     * show it). The content type is the one detected when the file was uploaded.
     */
    public function toResponse(): StreamedResponse
    {
        $disposition = $this->type === DocumentType::Logo ? 'inline' : 'attachment';

        return Storage::disk($this->disk)->response($this->path, $this->original_name, [
            'Content-Type' => $this->mime_type,
            'Cache-Control' => 'private, no-store',
        ], $disposition);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * @return BelongsTo<ProviderProfileChangeRequest, $this>
     */
    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ProviderProfileChangeRequest::class, 'provider_profile_change_request_id');
    }
}
