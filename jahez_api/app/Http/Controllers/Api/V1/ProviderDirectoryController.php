<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListProviderDirectoryRequest;
use App\Http\Resources\V1\ProviderDirectoryResource;
use App\Models\Factory;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProviderDirectoryController extends Controller
{
    private const RELATIONS = ['sectors', 'approvedServices.category'];

    /**
     * Approved providers a factory may contact (ADR-014). For a factory member only the
     * providers eligible for their factory are listed: approved, and targeting at least
     * one of the factory's sectors. IMC administrators see every approved provider.
     * filter[recommended] narrows a factory member's list to the providers among those
     * that offer a service recommended for the factory's current readiness category
     * (ADR-018): a recommendation narrows the eligible set, it never widens it.
     */
    public function index(ListProviderDirectoryRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        [$sortColumn, $sortDirection] = $request->sortColumnAndDirection();

        $providers = self::visibleTo($user)
            ->when($request->input('filter.service'), fn (Builder $query, string $code) => $query->whereHas(
                'approvedServices',
                fn (Builder $services) => $services->where('code', $code),
            ))
            ->when($request->input('filter.category'), fn (Builder $query, string $code) => $query->whereHas(
                'approvedServices.category',
                fn (Builder $categories) => $categories->where('code', $code),
            ))
            ->when($request->input('filter.sector'), fn (Builder $query, string $code) => $query->whereHas(
                'sectors',
                fn (Builder $sectors) => $sectors->where('code', $code),
            ))
            ->when($request->boolean('filter.recommended') ? $user->industrialFactory : null, fn (Builder $query, Factory $factory) => $query->whereHas(
                'approvedServices',
                fn (Builder $services) => $services->whereIn('catalog_services.id', $factory->recommendedCatalogServices()->select('catalog_services.id')),
            ))
            ->when($request->input('search'), fn (Builder $query, string $term) => $query->nameContains($term))
            ->orderBy($sortColumn, $sortDirection)
            ->orderBy('id')
            ->with(self::RELATIONS)
            ->paginate($request->perPage())
            ->withQueryString();

        return ProviderDirectoryResource::collection($providers);
    }

    /**
     * One provider's public profile, opened from the directory. A provider the user
     * could not find in the directory (not approved, or not eligible for their factory)
     * is reported as not found.
     */
    public function show(int $serviceProvider, #[CurrentUser] User $user): ProviderDirectoryResource
    {
        Gate::authorize('viewDirectory', ServiceProvider::class);

        return new ProviderDirectoryResource(
            self::visibleTo($user)->with(self::RELATIONS)->whereKey($serviceProvider)->firstOrFail()
        );
    }

    /**
     * @return Builder<ServiceProvider>
     */
    public static function visibleTo(User $user): Builder
    {
        return ServiceProvider::query()
            ->withExists(['activeDocuments as has_logo' => fn (Builder $documents) => $documents->where('type', DocumentType::Logo)])
            ->when(
                $user->industrialFactory,
                fn (Builder $query, $factory) => $query->eligibleFor($factory),
                fn (Builder $query) => $query->approved(),
            );
    }
}
