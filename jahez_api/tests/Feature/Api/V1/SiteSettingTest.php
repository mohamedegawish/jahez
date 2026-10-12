<?php

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Models\User;
use Database\Seeders\SiteSettingSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Landing-page content (texts in Arabic and English, plus the brand logo): IMC
| administrators edit it in bulk; visitors read the whole map without a token.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->seed(SiteSettingSeeder::class);
});

describe('public map', function () {
    it('returns every SPEC key without a token, with nulls until saved', function () {
        // Keys contain dots, so assert on the decoded map, not on dot paths.
        $map = $this->getJson(route('api.v1.public.site-settings.index'))->assertOk()->json('data');

        expect($map)->toHaveCount(count(SiteSetting::SPEC))
            ->and($map['hero.title_ar'])->toEqual(['type' => 'text', 'ar' => null, 'en' => null, 'image_url' => null])
            ->and($map['brand.logo']['type'])->toBe(SiteSetting::TYPE_IMAGE)
            ->and($map['brand.logo']['image_url'])->toBeNull();
    });

    it('reflects saved texts and answers 404 for the logo until one is uploaded', function () {
        SiteSetting::query()->where('key', 'hero.title_ar')->update(['value_ar' => 'T', 'value_en' => 'E']);
        SiteSetting::flushCache();

        $map = $this->getJson(route('api.v1.public.site-settings.index'))->assertOk()->json('data');

        expect($map['hero.title_ar']['ar'])->toBe('T')
            ->and($map['hero.title_ar']['en'])->toBe('E');

        $this->get(route('api.v1.public.site-settings.logo'))->assertNotFound();
    });
});

describe('administration', function () {
    it('refuses guests and members', function () {
        $this->getJson(route('api.v1.site-settings.index'))->assertUnauthorized();
        $this->patchJson(route('api.v1.site-settings.update'), ['settings' => []])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->factoryMember()->create());
        $this->getJson(route('api.v1.site-settings.index'))->assertForbidden();
        $this->patchJson(route('api.v1.site-settings.update'), ['settings' => ['hero' => ['title_ar' => ['ar' => 'X']]]])->assertNotFound();
    });

    it('lists every setting in SPEC order with its input metadata', function () {
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->getJson(route('api.v1.site-settings.index'))->assertOk();
        expect($response->json('data'))->toHaveCount(count(SiteSetting::SPEC));
        $response->assertJsonPath('data.0.key', 'brand.logo')
            ->assertJsonPath('data.0.section', 'brand')
            ->assertJsonPath('data.0.type', SiteSetting::TYPE_IMAGE);
    });

    it('updates texts in bulk, audits the changed keys and clears the cached map', function () {
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        // Prime the cache so the test proves the update clears it.
        expect($this->getJson(route('api.v1.public.site-settings.index'))->assertOk()->json('data')['hero.title_ar']['ar'])->toBeNull();

        $this->patchJson(route('api.v1.site-settings.update'), ['settings' => [
            'hero' => ['title_ar' => ['ar' => 'New Arabic', 'en' => 'New English']],
            'about' => ['fact1_value' => ['ar' => '7']],
        ]])->assertOk()->assertJsonPath('data.0.key', 'brand.logo');

        expect(SiteSetting::query()->findOrFail('hero.title_ar')->value_ar)->toBe('New Arabic')
            ->and(Cache::has('site_settings.map.v1'))->toBeFalse();

        $map = $this->getJson(route('api.v1.public.site-settings.index'))->assertOk()->json('data');
        expect($map['hero.title_ar']['ar'])->toBe('New Arabic')
            ->and($map['about.fact1_value']['ar'])->toBe('7');

        expect(AuditLog::query()->where('event', AuditEvent::SiteSettingsUpdated)->sole()->metadata)
            ->toEqual(['keys' => ['about.fact1_value', 'hero.title_ar']]);
    });

    it('ignores unchanged values in the audit and rejects unknown keys', function () {
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->patchJson(route('api.v1.site-settings.update'), ['settings' => [
            'hero' => ['title_ar' => ['ar' => null, 'en' => null]],
        ]])->assertOk();
        expect(AuditLog::query()->where('event', AuditEvent::SiteSettingsUpdated)->exists())->toBeFalse();

        $this->patchJson(route('api.v1.site-settings.update'), ['settings' => [
            'hero' => ['title_ar' => ['ar' => 'X']],
            'nope' => ['unknown' => ['ar' => 'X']],
            'brand' => ['logo' => ['ar' => 'X']],
        ]])->assertUnprocessable();
    });

    it('rejects over-long values', function () {
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->patchJson(route('api.v1.site-settings.update'), ['settings' => [
            'about' => ['fact1_value' => ['ar' => str_repeat('1', 21)]],
        ]])->assertUnprocessable();

        expect($response->json('errors'))->toHaveKey('settings.about.fact1_value.ar');
    });
});

describe('logo', function () {
    it('is uploaded, served publicly, replaced and removed, with an audit trail', function () {
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $this->post(route('api.v1.site-settings.logo.store'), ['file' => UploadedFile::fake()->image('logo.png', 400, 200)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.key', 'brand.logo')
            ->assertJsonPath('data.image_url', fn (string $url): bool => str_starts_with($url, '/public/site-settings/logo?v='));

        $firstPath = SiteSetting::query()->findOrFail('brand.logo')->image_path;

        app('auth')->forgetGuards();
        $map = $this->getJson(route('api.v1.public.site-settings.index'))->assertOk()->json('data');
        expect($map['brand.logo']['image_url'])->toStartWith('/public/site-settings/logo?v=');
        $this->get(route('api.v1.public.site-settings.logo'))->assertOk()->assertHeader('Content-Type', 'image/png');

        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $this->post(route('api.v1.site-settings.logo.store'), ['file' => UploadedFile::fake()->image('logo2.webp', 400, 200)], ['Accept' => 'application/json'])->assertOk();
        expect(Storage::disk('local')->exists($firstPath))->toBeFalse();

        $this->deleteJson(route('api.v1.site-settings.logo.destroy'))->assertOk()->assertJsonPath('data.image_url', null);

        app('auth')->forgetGuards();
        $this->get(route('api.v1.public.site-settings.logo'))->assertNotFound();

        expect(AuditLog::query()->where('event', AuditEvent::SiteSettingsUpdated)->count())->toBe(3);
    });

    it('rejects non-images and refuses guests and members', function () {
        $this->post(route('api.v1.site-settings.logo.store'), [], ['Accept' => 'application/json'])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->factoryMember()->create());
        $this->post(route('api.v1.site-settings.logo.store'), ['file' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])->assertNotFound();

        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $this->post(route('api.v1.site-settings.logo.store'), ['file' => UploadedFile::fake()->create('logo.txt', 10)], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    });
});
