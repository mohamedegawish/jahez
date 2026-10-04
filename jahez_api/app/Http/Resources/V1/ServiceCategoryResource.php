<?php

namespace App\Http\Resources\V1;

use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workbook service category, with its services when they were loaded.
 *
 * @mixin ServiceCategory
 */
class ServiceCategoryResource extends JsonResource
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
            'code' => $this->code,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'services' => CatalogServiceResource::collection($this->whenLoaded('services')),
        ];
    }
}
