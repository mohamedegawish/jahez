<?php

namespace App\Http\Resources\V1;

use App\Models\EvaluationCriterion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A weighted DOC §6 provider evaluation criterion. The weight is a decimal string, as
 * the source states it.
 *
 * @mixin EvaluationCriterion
 */
class EvaluationCriterionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->version,
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'sub_elements_ar' => $this->sub_elements_ar,
            'weight_percent' => $this->weight_percent,
            'verification_ar' => $this->verification_ar,
        ];
    }
}
