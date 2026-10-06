<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListCatalogServicesRequest;
use App\Http\Resources\V1\CatalogServiceResource;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\User;
use App\Readiness\ServiceEligibility;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CatalogServiceController extends Controller
{
    public function __construct(private readonly ServiceEligibility $eligibility) {}

    /**
     * The catalog's services in workbook order (reference data, 42 rows: not paginated).
     * A factory member sees only the services available to its readiness level
     * (ServiceEligibility, ADR-025); none before its first assessment. With
     * filter[eligible] they are narrowed to those at least one provider eligible for the
     * factory offers, and with filter[recommended] to its readiness roadmap's services.
     * IMC administrators and providers see the whole catalog.
     */
    public function index(ListCatalogServicesRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        $factory = $user->industrialFactory;

        $services = CatalogService::query()
            ->with('category')
            ->when($factory, fn (Builder $query, Factory $factory) => $query->whereIn('catalog_services.id', $this->eligibility->serviceIdsFor($factory)))
            ->when($request->input('filter.category'), fn (Builder $query, string $code) => $query->whereHas(
                'category',
                fn (Builder $categories) => $categories->where('code', $code),
            ))
            ->when($request->boolean('filter.eligible') ? $factory : null, fn (Builder $query, Factory $factory) => $query->whereIn(
                'catalog_services.id',
                $this->eligibility->constrainListings(DB::table('catalog_service_service_provider as l')->select('l.catalog_service_id'), $factory),
            ))
            ->when($request->boolean('filter.recommended') ? $factory : null, fn (Builder $query, Factory $factory) => $query->whereIn(
                'catalog_services.id',
                $factory->recommendedCatalogServices()->select('catalog_services.id'),
            ))
            ->orderBy('service_category_id')
            ->orderBy('sort_order')
            ->get();

        return CatalogServiceResource::collection($services);
    }

    /**
     * One catalog service. For a factory member, a service its readiness level does not
     * make available is reported as not found.
     */
    public function show(CatalogService $catalogService, #[CurrentUser] User $user): CatalogServiceResource
    {
        $factory = $user->industrialFactory;

        if ($factory !== null && ! $this->eligibility->isServiceAvailable($factory, $catalogService)) {
            abort(404);
        }

        return new CatalogServiceResource($catalogService->load('category'));
    }
}
