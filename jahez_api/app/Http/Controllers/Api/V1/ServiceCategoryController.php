<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ServiceCategoryResource;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Readiness\ServiceEligibility;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceCategoryController extends Controller
{
    public function __construct(private readonly ServiceEligibility $eligibility) {}

    /**
     * The seven workbook categories with their services (reference data: not paginated).
     * A factory member gets each category's services available to its readiness level
     * only (ADR-025).
     */
    public function index(#[CurrentUser] User $user): AnonymousResourceCollection
    {
        return ServiceCategoryResource::collection(
            ServiceCategory::query()->with(['services' => $this->servicesFor($user)])->orderBy('sort_order')->get()
        );
    }

    public function show(ServiceCategory $serviceCategory, #[CurrentUser] User $user): ServiceCategoryResource
    {
        return new ServiceCategoryResource($serviceCategory->load(['services' => $this->servicesFor($user)]));
    }

    /**
     * @return \Closure(Relation<*, *, *>): void
     */
    private function servicesFor(User $user): \Closure
    {
        $factory = $user->industrialFactory;

        return function (Relation $services) use ($factory): void {
            if ($factory !== null) {
                $services->whereIn('catalog_services.id', $this->eligibility->serviceIdsFor($factory));
            }
        };
    }
}
