<?php

namespace App\Http\Resources\V1;

use App\Models\FactoryAssessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A manual IMC classification (ADR-016). The tier, its readiness band and its approved
 * pathway are the source document's text for that tier. `classification_method` is
 * always "manual" and `score` always null: no official score exists until a scoring
 * method is approved (OQ-06, OQ-07). Expects maturityTier.pathwayLevel.pathway and
 * recordedBy to be loaded.
 *
 * @mixin FactoryAssessment
 */
class FactoryAssessmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tier = $this->maturityTier;
        $level = $tier?->pathwayLevel;

        return [
            'id' => $this->id,
            'factory_id' => $this->factory_id,
            'classification_method' => 'manual',
            'score' => null,
            'maturity_tier' => $tier === null ? null : [
                'code' => $tier->code,
                'name_ar' => $tier->name_ar,
                'name_en' => $tier->name_en,
                'readiness_band_ar' => $tier->readiness_band_ar,
                'approved_path_ar' => $tier->approved_path_ar,
                'pathway_level' => $level === null ? null : [
                    'code' => $level->code,
                    'name_ar' => $level->name_ar,
                    'pathway' => $level->pathway === null ? null : [
                        'code' => $level->pathway->code,
                        'name_ar' => $level->pathway->name_ar,
                    ],
                ],
            ],
            'justification' => $this->justification,
            'assessed_on' => $this->assessed_on->toDateString(),
            'recorded_by' => $this->recordedBy === null ? null : ['id' => $this->recordedBy->id, 'name' => $this->recordedBy->name],
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
