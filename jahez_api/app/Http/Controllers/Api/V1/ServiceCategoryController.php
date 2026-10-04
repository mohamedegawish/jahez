<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ServiceCategoryResource;
use App\Models\ServiceCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceCategoryController extends Controller
{
    /**
     * The seven workbook categories with their services (reference data: not paginated).
     */
    public function index(): AnonymousResourceCollection
    {
        return ServiceCategoryResource::collection(
            ServiceCategory::query()->with('services')->orderBy('sort_order')->get()
        );
    }

    public function show(ServiceCategory $serviceCategory): ServiceCategoryResource
    {
        return new ServiceCategoryResource($serviceCategory->load('services'));
    }
}
