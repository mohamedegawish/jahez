<?php

namespace App\Http\Resources\V1;

use App\Models\MaturityTier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A DOC §4 maturity tier. The source gives qualitative readiness bands only, so no
 * numeric score range is shown (OQ-06). Expects pathwayLevel.pathway to be loaded.
 *
 * @mixin MaturityTier
 */
class MaturityTierResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $level = $this->pathwayLevel;

        return [
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'readiness_band_ar' => $this->readiness_band_ar,
            'operational_state_ar' => $this->operational_state_ar,
            'approved_path_ar' => $this->approved_path_ar,
            'expected_impact_ar' => $this->expected_impact_ar,
            'pathway_level' => $level === null ? null : [
                'code' => $level->code,
                'name_ar' => $level->name_ar,
                'pathway' => $level->pathway === null ? null : [
                    'code' => $level->pathway->code,
                    'name_ar' => $level->pathway->name_ar,
                ],
            ],
        ];
    }
}
