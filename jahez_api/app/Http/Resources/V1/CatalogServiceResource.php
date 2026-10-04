<?php

namespace App\Http\Resources\V1;

use App\Models\CatalogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A catalog service as the workbook states it. The category is included only when the
 * caller loaded it.
 *
 * @mixin CatalogService
 */
class CatalogServiceResource extends JsonResource
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
            'category' => $this->whenLoaded('category', fn (): ?array => $this->category === null ? null : [
                'id' => $this->category->id,
                'code' => $this->category->code,
                'name_ar' => $this->category->name_ar,
                'name_en' => $this->category->name_en,
            ]),
        ];
    }
}
