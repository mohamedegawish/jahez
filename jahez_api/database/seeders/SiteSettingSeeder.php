<?php

namespace Database\Seeders;

use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

/**
 * Ensures every SiteSetting::SPEC row exists. Values stay null until an IMC
 * administrator saves them; the web client falls back to its built-in copy.
 * Idempotent and safe in every environment; contains no personal data.
 */
class SiteSettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SiteSetting::SPEC as $key => $meta) {
            SiteSetting::query()->firstOrCreate(
                ['key' => $key],
                ['section' => $meta['section'], 'type' => $meta['type']]
            );
        }
    }
}
