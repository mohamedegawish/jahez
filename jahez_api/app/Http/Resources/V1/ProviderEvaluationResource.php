<?php

namespace App\Http\Resources\V1;

use App\Models\ProviderEvaluation;
use App\Models\ProviderEvaluationScore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A DOC §6 provider evaluation (OQ-13 interim). `scoring` is "not_configured" while no
 * scale is approved: then every score, the weighted total and the pass-mark result are
 * null. `meets_pass_mark` is informational; approval stays a manual IMC decision.
 * Expects scores.criterion and recordedBy to be loaded.
 *
 * @mixin ProviderEvaluation
 */
class ProviderEvaluationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_provider_id' => $this->service_provider_id,
            'criteria_version' => $this->criteria_version,
            'summary' => $this->summary,
            'evaluated_on' => $this->evaluated_on->toDateString(),
            'scoring' => $this->scale_max === null ? 'not_configured' : 'scored',
            'scale_max' => $this->scale_max,
            'weighted_total' => $this->weighted_total,
            'pass_mark' => $this->pass_mark,
            'meets_pass_mark' => $this->meetsPassMark(),
            'criteria' => $this->scores->map(fn (ProviderEvaluationScore $score): array => [
                'code' => $score->criterion?->code,
                'name_ar' => $score->criterion?->name_ar,
                'weight_percent' => $score->criterion?->weight_percent,
                'score' => $score->score,
                'note' => $score->note,
            ])->values()->all(),
            'recorded_by' => $this->recordedBy === null ? null : ['id' => $this->recordedBy->id, 'name' => $this->recordedBy->name],
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
