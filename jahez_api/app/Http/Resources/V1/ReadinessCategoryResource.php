<?php

namespace App\Http\Resources\V1;

use App\Models\ReadinessCategory;
use App\Models\ReadinessRecommendation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A digital readiness category (ADR-018): its source names, description and total-score
 * range and, when recommendations.services.category is loaded, the source roadmap (focus,
 * steps and the recommended service lines with the catalog services they correspond to).
 * A line with no services is one the catalog does not offer (OQ-42).
 *
 * @mixin ReadinessCategory
 */
class ReadinessCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code->value,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'description_ar' => $this->description_ar,
            'min_score' => $this->min_score,
            'max_score' => $this->max_score,
            'roadmap' => $this->whenLoaded('recommendations', fn (): array => [
                'focus_ar' => $this->focus_ar,
                'steps_ar' => $this->steps_ar,
                'recommendations' => $this->recommendations->map(fn (ReadinessRecommendation $recommendation): array => [
                    'position' => $recommendation->sort_order,
                    'text_ar' => $recommendation->text_ar,
                    'services' => CatalogServiceResource::collection($recommendation->services),
                ])->all(),
            ]),
        ];
    }
}
