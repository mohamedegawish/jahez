<?php

namespace App\Http\Resources\V1;

use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One landing-page setting: its settings-page section, input type and length
 * limit, the saved language values (null until an administrator saves them) and
 * the public logo URL on the image row.
 *
 * @mixin SiteSetting
 */
class SiteSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'section' => $this->section,
            'type' => $this->type,
            'max' => SiteSetting::SPEC[$this->key]['max'] ?? null,
            'ar' => $this->value_ar,
            'en' => $this->value_en,
            'image_url' => $this->imageUrl(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
