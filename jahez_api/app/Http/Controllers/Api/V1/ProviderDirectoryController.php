<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListProviderDirectoryRequest;
use App\Http\Resources\V1\ProviderDirectoryResource;
use App\Models\Factory;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Readiness\ServiceEligibility;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProviderDirectoryController extends Controller
{
    public function __construct(private readonly ServiceEligibility $eligibility) {}

    /**
     * Approved providers a factory may contact (ADR-014). For a factory member only the
     * providers eligible for their factory are listed (ServiceEligibility, ADR-025):
     * approved, targeting at least one of the factory's sectors, and offering through an
     * approved listing a service available to the factory's readiness level; each
     * profile lists those services only. IMC administrators see every approved provider.
     * filter[service], filter[category] and filter[recommended] narrow the list to
     * providers offering a matching service the factory may use: they never widen it.
     */
    public function index(ListProviderDirectoryRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        [$sortColumn, $sortDirection] = $request->sortColumnAndDirection();
        $factory = $user->industrialFactory;

        $providers = $this->visibleTo($user)
            ->when($request->input('filter.service'), fn (Builder $query, string $code) => $query->whereHas(
                'approvedServices',
                fn (Builder $services) => $this->usable($services, $factory)->where('code', $code),
            ))
            ->when($request->input('filter.category'), fn (Builder $query, string $code) => $query->whereHas(
                'approvedServices',
                fn (Builder $services) => $this->usable($services, $factory)->whereHas('category', fn (Builder $categories) => $categories->where('code', $code)),
            ))
            ->when($request->input('filter.sector'), fn (Builder $query, string $code) => $query->whereHas(
                'sectors',
                fn (Builder $sectors) => $sectors->where('code', $code),
            ))
            ->when($request->boolean('filter.recommended') ? $factory : null, fn (Builder $query, Factory $factory) => $query->whereHas(
                'approvedServices',
                fn (Builder $services) => $this->usable($services, $factory)->whereIn('catalog_services.id', $factory->recommendedCatalogServices()->select('catalog_services.id')),
            ))
            ->when($request->input('search'), fn (Builder $query, string $term) => $query->nameContains($term))
            ->orderBy($sortColumn, $sortDirection)
            ->orderBy('id')
            ->with($this->relationsFor($factory))
            ->paginate($request->perPage())
            ->withQueryString();

        return ProviderDirectoryResource::collection($providers);
    }

    /**
     * One provider's public profile, opened from the directory. A provider the user
     * could not find in the directory is reported as not found.
     */
    public function show(int $serviceProvider, #[CurrentUser] User $user): ProviderDirectoryResource
    {
        Gate::authorize('viewDirectory', ServiceProvider::class);

        return new ProviderDirectoryResource(
            $this->visibleTo($user)->with($this->relationsFor($user->industrialFactory))->whereKey($serviceProvider)->firstOrFail()
        );
    }

    /**
     * The providers the user may find in the directory (ServiceEligibility).
     *
     * @return Builder<ServiceProvider>
     */
    private function visibleTo(User $user): Builder
    {
        return $this->eligibility->directoryProvidersFor($user->industrialFactory)
            ->withExists(['activeDocuments as has_logo' => fn (Builder $documents) => $documents->where('type', DocumentType::Logo)]);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function relationsFor(?Factory $factory): array
    {
        return [
            'sectors',
            'approvedServices' => fn (Relation $services) => $this->usable($services->getQuery(), $factory)->with('category'),
        ];
    }

    /**
     * Narrow a query over catalog services to those available to the factory's level; no
     * change for IMC.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $services
     * @return Builder<TModel>
     */
    private function usable(Builder $services, ?Factory $factory): Builder
    {
        if ($factory !== null) {
            $services->whereIn('catalog_services.id', $this->eligibility->serviceIdsFor($factory));
        }

        return $services;
    }
}
