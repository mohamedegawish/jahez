<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListCatalogServicesRequest;
use App\Http\Resources\V1\CatalogServiceResource;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogServiceController extends Controller
{
    /**
     * The catalog's services in workbook order (reference data, 42 rows: not paginated).
     * With filter[eligible], a factory member gets only the services that at least one
     * provider eligible for their factory offers.
     */
    public function index(ListCatalogServicesRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        $factory = $user->industrialFactory;

        $services = CatalogService::query()
            ->with('category')
            ->when($request->input('filter.category'), fn (Builder $query, string $code) => $query->whereHas(
                'category',
                fn (Builder $categories) => $categories->where('code', $code),
            ))
            ->when($request->boolean('filter.eligible') ? $factory : null, fn (Builder $query, Factory $factory) => $query->whereHas(
                'approvedProviders',
                fn (Builder $providers) => $providers->eligibleFor($factory),
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

    public function show(CatalogService $catalogService): CatalogServiceResource
    {
        return new CatalogServiceResource($catalogService->load('category'));
    }
}
