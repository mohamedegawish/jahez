<?php

namespace App\Http\Resources\V1;

use App\Models\LevelProviderRequirement;
use App\Models\Pathway;
use App\Models\PathwayLevel;
use App\Models\PathwayScopeItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A DOC §3 pathway with its levels, each level's scope items and the provider
 * requirements the source lists for it. Whether those requirements are hard gates is
 * open (OQ-14), so they are shown as text only. Expects levels.scopeItems and
 * levels.providerRequirements to be loaded.
 *
 * @mixin Pathway
 */
class PathwayResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'target_group_ar' => $this->target_group_ar,
            'levels' => $this->levels->map(fn (PathwayLevel $level): array => [
                'code' => $level->code,
                'name_ar' => $level->name_ar,
                'subtitle_ar' => $level->subtitle_ar,
                'name_en' => $level->name_en,
                'target_group_ar' => $level->target_group_ar,
                'scope_items' => $level->scopeItems->map(fn (PathwayScopeItem $item): array => [
                    'group_ar' => $item->group_ar,
                    'group_en' => $item->group_en,
                    'text_ar' => $item->text_ar,
                ])->values()->all(),
                'provider_requirements' => $level->providerRequirements
                    ->map(fn (LevelProviderRequirement $requirement): string => $requirement->text_ar)
                    ->values()
                    ->all(),
            ])->values()->all(),
        ];
    }
}
