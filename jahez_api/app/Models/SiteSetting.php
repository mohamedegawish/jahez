<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One editable piece of the public landing page (ADR-022 extends to all homepage
 * content, not only announcements). Rows are keyed by SiteSetting::SPEC; text rows
 * carry Arabic and English values, the single image row (brand.logo) the logo file.
 * Unset values are null and the web client falls back to its built-in copy.
 *
 * @property string $key
 * @property string $section
 * @property string $type
 * @property string|null $value_ar
 * @property string|null $value_en
 * @property string|null $image_disk
 * @property string|null $image_path
 * @property string|null $image_mime_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiteSetting extends Model
{
    public const TYPE_TEXT = 'text';

    public const TYPE_TEXTAREA = 'textarea';

    public const TYPE_IMAGE = 'image';

    /**
     * Every editable key: its settings-page section, its input type and the maximum
     * length of each language value (images have no language values). The seeder
     * creates exactly these rows; the update request rejects anything else.
     *
     * @var array<string, array{section: string, type: string, max: int|null}>
     */
    public const SPEC = [
        // Brand and logo.
        'brand.logo' => ['section' => 'brand', 'type' => self::TYPE_IMAGE, 'max' => null],
        'brand.logo_alt' => ['section' => 'brand', 'type' => self::TYPE_TEXT, 'max' => 200],
        // Public header.
        'header.brand_ar' => ['section' => 'header', 'type' => self::TYPE_TEXT, 'max' => 40],
        'header.brand_en' => ['section' => 'header', 'type' => self::TYPE_TEXT, 'max' => 40],
        'header.nav_home' => ['section' => 'header', 'type' => self::TYPE_TEXT, 'max' => 80],
        'header.nav_register_factory' => ['section' => 'header', 'type' => self::TYPE_TEXT, 'max' => 80],
        'header.nav_register_provider' => ['section' => 'header', 'type' => self::TYPE_TEXT, 'max' => 80],
        'header.portal_button' => ['section' => 'header', 'type' => self::TYPE_TEXT, 'max' => 80],
        'header.login_button' => ['section' => 'header', 'type' => self::TYPE_TEXT, 'max' => 80],
        // Public footer.
        'footer.line' => ['section' => 'footer', 'type' => self::TYPE_TEXT, 'max' => 200],
        'footer.link_factory' => ['section' => 'footer', 'type' => self::TYPE_TEXT, 'max' => 80],
        'footer.link_provider' => ['section' => 'footer', 'type' => self::TYPE_TEXT, 'max' => 80],
        'footer.link_login' => ['section' => 'footer', 'type' => self::TYPE_TEXT, 'max' => 80],
        // Hero.
        'hero.title_latin' => ['section' => 'hero', 'type' => self::TYPE_TEXT, 'max' => 40],
        'hero.title_ar' => ['section' => 'hero', 'type' => self::TYPE_TEXT, 'max' => 40],
        'hero.kicker' => ['section' => 'hero', 'type' => self::TYPE_TEXT, 'max' => 200],
        'hero.subtitle' => ['section' => 'hero', 'type' => self::TYPE_TEXTAREA, 'max' => 1000],
        'hero.subtitle_authority' => ['section' => 'hero', 'type' => self::TYPE_TEXT, 'max' => 120],
        'hero.fact1' => ['section' => 'hero', 'type' => self::TYPE_TEXT, 'max' => 120],
        'hero.fact2' => ['section' => 'hero', 'type' => self::TYPE_TEXT, 'max' => 120],
        'hero.fact3' => ['section' => 'hero', 'type' => self::TYPE_TEXT, 'max' => 120],
        'hero.portal_factory_title' => ['section' => 'hero', 'type' => self::TYPE_TEXT, 'max' => 120],
        'hero.portal_factory_desc' => ['section' => 'hero', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'hero.portal_provider_title' => ['section' => 'hero', 'type' => self::TYPE_TEXT, 'max' => 120],
        'hero.portal_provider_desc' => ['section' => 'hero', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'hero.portal_admin_title' => ['section' => 'hero', 'type' => self::TYPE_TEXT, 'max' => 120],
        'hero.portal_admin_desc' => ['section' => 'hero', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'hero.portal_join_title' => ['section' => 'hero', 'type' => self::TYPE_TEXT, 'max' => 120],
        'hero.portal_join_desc' => ['section' => 'hero', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'hero.join_factory_button' => ['section' => 'hero', 'type' => self::TYPE_TEXT, 'max' => 80],
        'hero.join_provider_button' => ['section' => 'hero', 'type' => self::TYPE_TEXT, 'max' => 80],
        'hero.cta_catalog' => ['section' => 'hero', 'type' => self::TYPE_TEXT, 'max' => 80],
        'hero.cta_assessment' => ['section' => 'hero', 'type' => self::TYPE_TEXT, 'max' => 80],
        'hero.cta_login' => ['section' => 'hero', 'type' => self::TYPE_TEXT, 'max' => 80],
        // Join-the-platform section (visitors).
        'join.title' => ['section' => 'join', 'type' => self::TYPE_TEXT, 'max' => 200],
        'join.desc' => ['section' => 'join', 'type' => self::TYPE_TEXTAREA, 'max' => 1000],
        'join.card_factory_title' => ['section' => 'join', 'type' => self::TYPE_TEXT, 'max' => 120],
        'join.card_factory_desc' => ['section' => 'join', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'join.card_provider_title' => ['section' => 'join', 'type' => self::TYPE_TEXT, 'max' => 120],
        'join.card_provider_desc' => ['section' => 'join', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'join.card_login_title' => ['section' => 'join', 'type' => self::TYPE_TEXT, 'max' => 120],
        'join.card_login_desc' => ['section' => 'join', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        // Value proposition.
        'value.title' => ['section' => 'value', 'type' => self::TYPE_TEXT, 'max' => 200],
        'value.desc' => ['section' => 'value', 'type' => self::TYPE_TEXTAREA, 'max' => 1000],
        // Service categories.
        'categories.cat1_title' => ['section' => 'categories', 'type' => self::TYPE_TEXT, 'max' => 120],
        'categories.cat1_desc' => ['section' => 'categories', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'categories.cat2_title' => ['section' => 'categories', 'type' => self::TYPE_TEXT, 'max' => 120],
        'categories.cat2_desc' => ['section' => 'categories', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'categories.cat3_title' => ['section' => 'categories', 'type' => self::TYPE_TEXT, 'max' => 120],
        'categories.cat3_desc' => ['section' => 'categories', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'categories.cat4_title' => ['section' => 'categories', 'type' => self::TYPE_TEXT, 'max' => 120],
        'categories.cat4_desc' => ['section' => 'categories', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'categories.cat5_title' => ['section' => 'categories', 'type' => self::TYPE_TEXT, 'max' => 120],
        'categories.cat5_desc' => ['section' => 'categories', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'categories.cat6_title' => ['section' => 'categories', 'type' => self::TYPE_TEXT, 'max' => 120],
        'categories.cat6_desc' => ['section' => 'categories', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'categories.cat7_title' => ['section' => 'categories', 'type' => self::TYPE_TEXT, 'max' => 120],
        'categories.cat7_desc' => ['section' => 'categories', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'categories.browse_hint' => ['section' => 'categories', 'type' => self::TYPE_TEXT, 'max' => 120],
        'categories.browse_cta' => ['section' => 'categories', 'type' => self::TYPE_TEXT, 'max' => 80],
        'categories.assess_title' => ['section' => 'categories', 'type' => self::TYPE_TEXT, 'max' => 120],
        'categories.assess_desc' => ['section' => 'categories', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'categories.assess_hint' => ['section' => 'categories', 'type' => self::TYPE_TEXT, 'max' => 120],
        'categories.assess_cta' => ['section' => 'categories', 'type' => self::TYPE_TEXT, 'max' => 80],
        // About the platform.
        'about.title' => ['section' => 'about', 'type' => self::TYPE_TEXT, 'max' => 200],
        'about.p1' => ['section' => 'about', 'type' => self::TYPE_TEXTAREA, 'max' => 2000],
        'about.p2' => ['section' => 'about', 'type' => self::TYPE_TEXTAREA, 'max' => 2000],
        'about.badge1' => ['section' => 'about', 'type' => self::TYPE_TEXT, 'max' => 120],
        'about.badge2' => ['section' => 'about', 'type' => self::TYPE_TEXT, 'max' => 120],
        'about.badge3' => ['section' => 'about', 'type' => self::TYPE_TEXT, 'max' => 120],
        'about.fact1_value' => ['section' => 'about', 'type' => self::TYPE_TEXT, 'max' => 20],
        'about.fact1_label' => ['section' => 'about', 'type' => self::TYPE_TEXT, 'max' => 200],
        'about.fact2_value' => ['section' => 'about', 'type' => self::TYPE_TEXT, 'max' => 20],
        'about.fact2_label' => ['section' => 'about', 'type' => self::TYPE_TEXT, 'max' => 200],
        'about.fact3_value' => ['section' => 'about', 'type' => self::TYPE_TEXT, 'max' => 20],
        'about.fact3_label' => ['section' => 'about', 'type' => self::TYPE_TEXT, 'max' => 200],
        // Platform services.
        'services.title' => ['section' => 'services', 'type' => self::TYPE_TEXT, 'max' => 200],
        'services.svc1_title' => ['section' => 'services', 'type' => self::TYPE_TEXT, 'max' => 120],
        'services.svc1_desc' => ['section' => 'services', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'services.svc2_title' => ['section' => 'services', 'type' => self::TYPE_TEXT, 'max' => 120],
        'services.svc2_desc' => ['section' => 'services', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'services.svc3_title' => ['section' => 'services', 'type' => self::TYPE_TEXT, 'max' => 120],
        'services.svc3_desc' => ['section' => 'services', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'services.svc4_title' => ['section' => 'services', 'type' => self::TYPE_TEXT, 'max' => 120],
        'services.svc4_desc' => ['section' => 'services', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        // How to use (4 steps).
        'steps.title' => ['section' => 'steps', 'type' => self::TYPE_TEXT, 'max' => 200],
        'steps.intro' => ['section' => 'steps', 'type' => self::TYPE_TEXTAREA, 'max' => 1000],
        'steps.step1_title' => ['section' => 'steps', 'type' => self::TYPE_TEXT, 'max' => 120],
        'steps.step1_desc' => ['section' => 'steps', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'steps.step1_cta' => ['section' => 'steps', 'type' => self::TYPE_TEXT, 'max' => 80],
        'steps.step2_title' => ['section' => 'steps', 'type' => self::TYPE_TEXT, 'max' => 120],
        'steps.step2_desc' => ['section' => 'steps', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'steps.step2_cta' => ['section' => 'steps', 'type' => self::TYPE_TEXT, 'max' => 80],
        'steps.step3_title' => ['section' => 'steps', 'type' => self::TYPE_TEXT, 'max' => 120],
        'steps.step3_desc' => ['section' => 'steps', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'steps.step3_cta' => ['section' => 'steps', 'type' => self::TYPE_TEXT, 'max' => 80],
        'steps.step4_title' => ['section' => 'steps', 'type' => self::TYPE_TEXT, 'max' => 120],
        'steps.step4_desc' => ['section' => 'steps', 'type' => self::TYPE_TEXTAREA, 'max' => 500],
        'steps.step4_cta' => ['section' => 'steps', 'type' => self::TYPE_TEXT, 'max' => 80],
    ];

    /**
     * The primary key is the setting key itself.
     */
    protected $primaryKey = 'key';

    /**
     * Keys are strings and never auto-increment.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Nothing is fillable: rows are created by the seeder and values change only
     * through the bulk update (texts) and the logo actions (the file).
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * Keys of the text rows, in SPEC order (the settings-page order).
     *
     * @return list<string>
     */
    public static function textKeys(): array
    {
        return array_keys(array_filter(
            self::SPEC,
            fn (array $meta): bool => $meta['type'] !== self::TYPE_IMAGE
        ));
    }

    /**
     * The whole public map, cached until any setting changes: key to its language
     * values, plus the logo URL for the image row. Missing rows read as nulls, so a
     * database the seeder has not reached yet still answers.
     *
     * @return array<string, array{type: string, ar: string|null, en: string|null, image_url: string|null}>
     */
    public static function allCached(): array
    {
        /** @var array<string, array{type: string, ar: string|null, en: string|null, image_url: string|null}> $map */
        $map = Cache::rememberForever('site_settings.map.v1', function (): array {
            $rows = self::query()->get()->keyBy('key');

            $map = [];
            foreach (self::SPEC as $key => $meta) {
                /** @var SiteSetting|null $row */
                $row = $rows->get($key);
                $map[$key] = [
                    'type' => $meta['type'],
                    'ar' => $row?->value_ar,
                    'en' => $row?->value_en,
                    'image_url' => $row?->imageUrl(),
                ];
            }

            return $map;
        });

        return $map;
    }

    public static function flushCache(): void
    {
        Cache::forget('site_settings.map.v1');
    }

    /**
     * The settings-page order.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function specOrder(Builder $query): void
    {
        $order = implode(',', array_map(
            fn (string $key): string => "'".str_replace("'", "''", $key)."'",
            array_keys(self::SPEC)
        ));
        $query->orderByRaw("FIELD(`key`, {$order})");
    }

    public function isImage(): bool
    {
        return (self::SPEC[$this->key]['type'] ?? null) === self::TYPE_IMAGE;
    }

    public function hasImage(): bool
    {
        return $this->image_path !== null && $this->image_disk !== null;
    }

    /**
     * The public path of the logo; the version query changes with the row, so a
     * replaced logo is fetched again.
     */
    public function imageUrl(): ?string
    {
        if (! $this->hasImage()) {
            return null;
        }
        $version = $this->updated_at?->getTimestamp() ?? 0;

        return "/public/site-settings/logo?v={$version}";
    }

    /**
     * The logo file inline, with the content type detected at upload.
     */
    public function imageResponse(): StreamedResponse
    {
        return Storage::disk((string) $this->image_disk)->response((string) $this->image_path, null, [
            'Content-Type' => (string) $this->image_mime_type,
        ], 'inline');
    }

    public function deleteImageFile(): void
    {
        if ($this->hasImage()) {
            Storage::disk((string) $this->image_disk)->delete((string) $this->image_path);
        }
    }
}
