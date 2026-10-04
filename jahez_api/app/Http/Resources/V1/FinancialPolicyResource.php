<?php

namespace App\Http\Resources\V1;

use App\Enums\FinancialPolicyScope;
use App\Models\FinancialPolicy;
use App\Models\FinancialPolicyVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A financial policy (ADR-023): its kind and scope, the version in effect today, the
 * version being prepared, and, on the detail view, every version. Expects the scope
 * relations to be loaded; the `currentVersion` and `openVersion` relations are set by the
 * controller (FinancialPolicyController::withSummaries()).
 *
 * @mixin FinancialPolicy
 */
class FinancialPolicyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = $this->resource->relationLoaded('currentVersion') ? $this->resource->getRelation('currentVersion') : null;
        $open = $this->resource->relationLoaded('openVersion') ? $this->resource->getRelation('openVersion') : null;

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'kind_label_ar' => $this->kind->labelAr(),
            'decision_needed' => $this->kind->decisionNeeded(),
            'scope' => [
                'type' => $this->scope_type->value,
                'id' => $this->scope_id,
                'code' => $this->scope_type === FinancialPolicyScope::Sector ? $this->sector?->code : ($this->scope_type === FinancialPolicyScope::CatalogService ? $this->catalogService?->code : null),
                'label_ar' => $this->scope_type->labelAr(),
                'name' => $this->scopeName(),
            ],
            'name_ar' => $this->name_ar,
            'description_ar' => $this->description_ar,
            'current_version' => $current instanceof FinancialPolicyVersion ? $this->summary($current) : null,
            'open_version' => $open instanceof FinancialPolicyVersion ? $this->summary($open) : null,
            'versions' => $this->whenLoaded('versions', fn () => FinancialPolicyVersionResource::collection($this->versions)),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(FinancialPolicyVersion $version): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'status' => $version->status->value,
            'effective_status' => $version->effectiveStatus(),
            'effective_from' => $version->effective_from->toDateString(),
            'effective_to' => $version->effective_to?->toDateString(),
            'parameters' => $version->parameters,
        ];
    }
}
