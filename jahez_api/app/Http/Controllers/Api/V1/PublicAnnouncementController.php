<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListRequest;
use App\Http\Requests\Api\V1\SavePublicAnnouncementRequest;
use App\Http\Resources\V1\PublicAnnouncementResource;
use App\Models\AuditLog;
use App\Models\PublicAnnouncement;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Landing-page announcements (ADR-022). Visitors read live ones without a token; IMC
 * administrators with announcements.manage write drafts, publish, unpublish, set the
 * cover image and delete drafts. Every change is audited (title and field names only).
 */
class PublicAnnouncementController extends Controller
{
    /**
     * At most this many live announcements are returned to visitors (the strip shows
     * them all, so a technical bound, not a business rule).
     */
    public const PUBLIC_LIMIT = 30;

    // ─── Public ───

    public function publicIndex(): AnonymousResourceCollection
    {
        return PublicAnnouncementResource::collection(
            PublicAnnouncement::query()->live()->displayOrder()->limit(self::PUBLIC_LIMIT)->get()
        );
    }

    public function publicShow(int $publicAnnouncement): PublicAnnouncementResource
    {
        return new PublicAnnouncementResource(PublicAnnouncement::query()->live()->findOrFail($publicAnnouncement));
    }

    /**
     * A live announcement's cover; a draft's, scheduled or ended one's is not found.
     */
    public function publicCover(int $publicAnnouncement): StreamedResponse
    {
        $announcement = PublicAnnouncement::query()->live()->findOrFail($publicAnnouncement);
        abort_unless($announcement->hasCover(), 404);

        return $announcement->coverResponse();
    }

    // ─── IMC administration ───

    public function index(ListRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PublicAnnouncement::class);
        $request->validate(['filter.published' => ['sometimes', 'boolean'], 'filter' => ['sometimes', 'array:published']]);

        return PublicAnnouncementResource::collection(
            PublicAnnouncement::query()
                ->when($request->has('filter.published'), fn (Builder $query) => $request->boolean('filter.published')
                    ? $query->whereNotNull('published_at')
                    : $query->whereNull('published_at'))
                ->displayOrder()
                ->paginate($request->perPage())
                ->withQueryString()
        );
    }

    public function store(SavePublicAnnouncementRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $announcement = DB::transaction(function () use ($request, $user): PublicAnnouncement {
            $announcement = new PublicAnnouncement($request->validated());
            $announcement->created_by_user_id = $user->id;
            $announcement->updated_by_user_id = $user->id;
            $announcement->save();

            AuditLog::record(AuditEvent::AnnouncementCreated, $user, $announcement, ['title' => $announcement->title]);

            return $announcement;
        });

        return (new PublicAnnouncementResource($announcement))->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(PublicAnnouncement $publicAnnouncement): PublicAnnouncementResource
    {
        Gate::authorize('update', $publicAnnouncement);

        return new PublicAnnouncementResource($publicAnnouncement);
    }

    /**
     * A published announcement may be edited: the change is visible at once.
     */
    public function update(SavePublicAnnouncementRequest $request, PublicAnnouncement $publicAnnouncement, #[CurrentUser] User $user): PublicAnnouncementResource
    {
        DB::transaction(function () use ($request, $publicAnnouncement, $user): void {
            $locked = PublicAnnouncement::query()->lockForUpdate()->findOrFail($publicAnnouncement->id);
            $locked->fill($request->validated());
            $changed = array_keys($locked->getDirty());

            if ($changed === []) {
                return;
            }

            if ($locked->ends_at !== null && $locked->starts_at !== null && $locked->ends_at->lessThanOrEqualTo($locked->starts_at)) {
                throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
            }

            sort($changed);
            $locked->updated_by_user_id = $user->id;
            $locked->save();
            AuditLog::record(AuditEvent::AnnouncementUpdated, $user, $locked, ['fields' => $changed]);
        });

        return new PublicAnnouncementResource($publicAnnouncement->refresh());
    }

    public function publish(PublicAnnouncement $publicAnnouncement, #[CurrentUser] User $user): PublicAnnouncementResource
    {
        return $this->setPublished($publicAnnouncement, $user, true);
    }

    public function unpublish(PublicAnnouncement $publicAnnouncement, #[CurrentUser] User $user): PublicAnnouncementResource
    {
        return $this->setPublished($publicAnnouncement, $user, false);
    }

    /**
     * Only a draft is deleted; a published announcement is unpublished first, so what
     * visitors saw stays on record.
     */
    public function destroy(PublicAnnouncement $publicAnnouncement, #[CurrentUser] User $user): Response
    {
        Gate::authorize('update', $publicAnnouncement);

        $announcement = DB::transaction(function () use ($publicAnnouncement, $user): PublicAnnouncement {
            $locked = PublicAnnouncement::query()->lockForUpdate()->findOrFail($publicAnnouncement->id);
            if ($locked->isPublished()) {
                throw new ConflictHttpException('Unpublish the announcement before deleting it.');
            }

            AuditLog::record(AuditEvent::AnnouncementDeleted, $user, $locked, ['title' => $locked->title]);
            $locked->delete();

            return $locked;
        });
        $announcement->deleteCoverFile();

        return response()->noContent();
    }

    /**
     * Set or replace the cover image (jpg, png, webp; the logo size limit). The previous
     * file is deleted once the new one is recorded.
     */
    public function storeCover(Request $request, PublicAnnouncement $publicAnnouncement, #[CurrentUser] User $user): PublicAnnouncementResource
    {
        Gate::authorize('update', $publicAnnouncement);
        $request->validate([
            'file' => ['required', 'file', 'mimes:'.implode(',', (array) config('jahez.documents.logo_mimes')), 'max:'.(int) config('jahez.documents.logo_max_kb')],
        ]);

        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422, 'Send one file.');

        $disk = (string) config('jahez.documents.disk');
        $extension = strtolower($file->guessExtension() ?? $file->extension());
        $path = Storage::disk($disk)->putFileAs('announcements', $file, Str::uuid()->toString().'.'.$extension);
        abort_if($path === false, 500, 'The uploaded file could not be stored.');

        $previous = null;
        try {
            DB::transaction(function () use ($publicAnnouncement, $user, $disk, $path, $file, &$previous): void {
                $locked = PublicAnnouncement::query()->lockForUpdate()->findOrFail($publicAnnouncement->id);
                $previous = $locked->hasCover() ? clone $locked : null;
                $locked->cover_disk = $disk;
                $locked->cover_path = $path;
                $locked->cover_mime_type = (string) $file->getMimeType();
                $locked->updated_by_user_id = $user->id;
                $locked->save();
                AuditLog::record(AuditEvent::AnnouncementUpdated, $user, $locked, ['fields' => ['cover']]);
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
        $previous?->deleteCoverFile();

        return new PublicAnnouncementResource($publicAnnouncement->refresh());
    }

    public function destroyCover(PublicAnnouncement $publicAnnouncement, #[CurrentUser] User $user): PublicAnnouncementResource
    {
        Gate::authorize('update', $publicAnnouncement);

        $previous = DB::transaction(function () use ($publicAnnouncement, $user): ?PublicAnnouncement {
            $locked = PublicAnnouncement::query()->lockForUpdate()->findOrFail($publicAnnouncement->id);
            if (! $locked->hasCover()) {
                return null;
            }
            $previous = clone $locked;
            $locked->cover_disk = null;
            $locked->cover_path = null;
            $locked->cover_mime_type = null;
            $locked->updated_by_user_id = $user->id;
            $locked->save();
            AuditLog::record(AuditEvent::AnnouncementUpdated, $user, $locked, ['fields' => ['cover']]);

            return $previous;
        });
        $previous?->deleteCoverFile();

        return new PublicAnnouncementResource($publicAnnouncement->refresh());
    }

    /**
     * Any announcement's cover, for the administrators' preview of drafts.
     */
    public function cover(PublicAnnouncement $publicAnnouncement): StreamedResponse
    {
        Gate::authorize('update', $publicAnnouncement);
        abort_unless($publicAnnouncement->hasCover(), 404);

        return $publicAnnouncement->coverResponse();
    }

    private function setPublished(PublicAnnouncement $announcement, User $user, bool $publish): PublicAnnouncementResource
    {
        Gate::authorize('update', $announcement);

        DB::transaction(function () use ($announcement, $user, $publish): void {
            $locked = PublicAnnouncement::query()->lockForUpdate()->findOrFail($announcement->id);
            if ($locked->isPublished() === $publish) {
                throw new ConflictHttpException($publish ? 'The announcement is already published.' : 'The announcement is not published.');
            }

            $locked->published_at = $publish ? now() : null;
            $locked->updated_by_user_id = $user->id;
            $locked->save();
            AuditLog::record($publish ? AuditEvent::AnnouncementPublished : AuditEvent::AnnouncementUnpublished, $user, $locked, ['title' => $locked->title]);
        });

        return new PublicAnnouncementResource($announcement->refresh());
    }
}
