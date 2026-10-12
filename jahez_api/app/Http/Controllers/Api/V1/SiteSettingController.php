<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateSiteSettingsRequest;
use App\Http\Resources\V1\SiteSettingResource;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Landing-page content (texts in Arabic and English, plus the brand logo).
 * Visitors read the whole map and the logo without a token; IMC administrators
 * with site_settings.manage update texts in bulk and set the logo. Every change
 * is audited (keys only, never values) and clears the cached map.
 */
class SiteSettingController extends Controller
{
    // ─── Public ───

    /**
     * The whole map keyed by setting key. Unset values are null; the web client
     * falls back to its built-in copy.
     */
    public function publicMap(): JsonResponse
    {
        return response()->json(['data' => SiteSetting::allCached()]);
    }

    /**
     * The brand logo file; 404 until an administrator uploads one.
     */
    public function publicLogo(): StreamedResponse
    {
        $logo = SiteSetting::query()->find('brand.logo');
        abort_unless($logo instanceof SiteSetting && $logo->hasImage(), 404);

        return $logo->imageResponse();
    }

    // ─── IMC administration ───

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', SiteSetting::class);

        return SiteSettingResource::collection(SiteSetting::query()->specOrder()->get());
    }

    /**
     * Bulk text update. Rows the seeder has not created yet are created; keys
     * whose values did not change are left alone and unmentioned in the audit.
     */
    public function update(UpdateSiteSettingsRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        $settings = [];
        foreach ((array) $request->input('settings', []) as $section => $names) {
            foreach ((array) $names as $name => $values) {
                $settings["{$section}.{$name}"] = $values;
            }
        }

        $changed = DB::transaction(function () use ($settings, $user): array {
            $changed = [];
            foreach ($settings as $key => $values) {
                $values = (array) $values;
                $setting = SiteSetting::query()->lockForUpdate()->findOrFail($key);
                abort_if($setting->isImage(), 422, 'Images change through the logo actions.');

                $dirty = false;
                foreach (['ar' => 'value_ar', 'en' => 'value_en'] as $input => $column) {
                    if (! array_key_exists($input, $values)) {
                        continue;
                    }
                    if ($setting->{$column} !== $values[$input]) {
                        $setting->{$column} = $values[$input];
                        $dirty = true;
                    }
                }

                if ($dirty) {
                    $setting->save();
                    $changed[] = $key;
                }
            }

            sort($changed);
            if ($changed !== []) {
                AuditLog::record(AuditEvent::SiteSettingsUpdated, $user, null, ['keys' => $changed]);
            }

            return $changed;
        });

        if ($changed !== []) {
            SiteSetting::flushCache();
        }

        return SiteSettingResource::collection(SiteSetting::query()->specOrder()->get());
    }

    /**
     * Set or replace the brand logo (jpg, png, webp; the logo size limit). The
     * previous file is deleted once the new one is recorded.
     */
    public function storeLogo(Request $request, #[CurrentUser] User $user): SiteSettingResource
    {
        Gate::authorize('update', new SiteSetting);
        $request->validate([
            'file' => ['required', 'file', 'mimes:'.implode(',', (array) config('jahez.documents.logo_mimes')), 'max:'.(int) config('jahez.documents.logo_max_kb')],
        ]);

        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422, 'Send one file.');

        $disk = (string) config('jahez.documents.disk');
        $extension = strtolower($file->guessExtension() ?? $file->extension());
        $path = Storage::disk($disk)->putFileAs('site-settings', $file, 'logo.'.$extension);
        abort_if($path === false, 500, 'The uploaded file could not be stored.');

        $previous = null;
        try {
            DB::transaction(function () use ($user, $disk, $path, $file, &$previous): void {
                $logo = SiteSetting::query()->lockForUpdate()->firstOrCreate(
                    ['key' => 'brand.logo'],
                    ['section' => 'brand', 'type' => SiteSetting::TYPE_IMAGE]
                );
                $previous = $logo->hasImage() ? clone $logo : null;
                $logo->image_disk = $disk;
                $logo->image_path = $path;
                $logo->image_mime_type = (string) $file->getMimeType();
                $logo->save();
                AuditLog::record(AuditEvent::SiteSettingsUpdated, $user, null, ['keys' => ['brand.logo']]);
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
        $previous?->deleteImageFile();
        SiteSetting::flushCache();

        return new SiteSettingResource(SiteSetting::query()->findOrFail('brand.logo'));
    }

    public function destroyLogo(#[CurrentUser] User $user): SiteSettingResource
    {
        Gate::authorize('update', new SiteSetting);

        $previous = DB::transaction(function () use ($user): ?SiteSetting {
            $logo = SiteSetting::query()->lockForUpdate()->firstOrCreate(
                ['key' => 'brand.logo'],
                ['section' => 'brand', 'type' => SiteSetting::TYPE_IMAGE]
            );
            if (! $logo->hasImage()) {
                return null;
            }
            $previous = clone $logo;
            $logo->image_disk = null;
            $logo->image_path = null;
            $logo->image_mime_type = null;
            $logo->save();
            AuditLog::record(AuditEvent::SiteSettingsUpdated, $user, null, ['keys' => ['brand.logo']]);

            return $previous;
        });
        $previous?->deleteImageFile();
        SiteSetting::flushCache();

        return new SiteSettingResource(SiteSetting::query()->findOrFail('brand.logo'));
    }
}
