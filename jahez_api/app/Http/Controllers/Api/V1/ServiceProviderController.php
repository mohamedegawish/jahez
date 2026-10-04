<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListServiceProvidersRequest;
use App\Http\Requests\Api\V1\StoreServiceProviderRequest;
use App\Http\Requests\Api\V1\UpdateServiceProviderRequest;
use App\Http\Resources\V1\ServiceProviderResource;
use App\Models\AuditLog;
use App\Models\CatalogService;
use App\Models\Sector;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ServiceProviderController extends Controller
{
    /**
     * Relations the full profile resource shows.
     */
    public const PROFILE_RELATIONS = ['sectors', 'services.category', 'activeDocuments', 'openChangeRequest.documents'];

    public function index(ListServiceProvidersRequest $request): AnonymousResourceCollection
    {
        $serviceProviders = ServiceProvider::query()
            ->when($request->input('filter.approval_status'), fn (Builder $query, string $status) => $query->where('approval_status', $status))
            ->when($request->input('filter.sector'), fn (Builder $query, string $code) => $query->whereHas(
                'sectors',
                fn (Builder $sectors) => $sectors->where('code', $code),
            ))
            ->when($request->input('filter.service'), fn (Builder $query, string $code) => $query->whereHas(
                'services',
                fn (Builder $services) => $services->where('code', $code),
            ))
            ->when($request->input('filter.category'), fn (Builder $query, string $code) => $query->whereHas(
                'services.category',
                fn (Builder $categories) => $categories->where('code', $code),
            ))
            ->when($request->input('filter.listing_status'), fn (Builder $query, string $status) => $query->whereHas(
                'services',
                fn (Builder $services) => $services->where('catalog_service_service_provider.status', $status),
            ))
            ->when($request->input('search'), fn (Builder $query, string $term) => $query->nameContains($term))
            ->with(self::PROFILE_RELATIONS)
            ->when($request->sortColumnAndDirection(), fn (Builder $query, array $sort) => $query->orderBy($sort[0], $sort[1]))
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return ServiceProviderResource::collection($serviceProviders);
    }

    /**
     * Create a provider. It starts as pending: factories see it only after an IMC
     * administrator approves it (ADR-014).
     */
    public function store(StoreServiceProviderRequest $request, #[CurrentUser] User $actor): JsonResponse
    {
        $serviceProvider = DB::transaction(function () use ($request, $actor): ServiceProvider {
            $serviceProvider = ServiceProvider::query()->create($request->safe()->only([...ServiceProvider::PROFILE_FIELDS, ...ServiceProvider::LEGAL_FIELDS]));
            $sectorCodes = $request->validated('sectors', []);
            $serviceCodes = $request->validated('services', []);
            $serviceProvider->sectors()->sync(Sector::query()->whereIn('code', $sectorCodes)->pluck('id'));
            $serviceProvider->services()->sync(CatalogService::query()->whereIn('code', $serviceCodes)->pluck('id'));

            AuditLog::record(AuditEvent::ServiceProviderCreated, $actor, $serviceProvider, [
                'name' => $serviceProvider->name,
                'sectors' => $sectorCodes,
                'services' => $serviceCodes,
            ]);

            return $serviceProvider;
        });

        return (new ServiceProviderResource($serviceProvider->load(self::PROFILE_RELATIONS)))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(ServiceProvider $serviceProvider): ServiceProviderResource
    {
        Gate::authorize('view', $serviceProvider);

        return new ServiceProviderResource($serviceProvider->load(self::PROFILE_RELATIONS));
    }

    /**
     * Update the workbook profile, sectors and services. The audit entry records the name
     * and the sector and service lists before and after, and only the names of the other
     * changed fields, because those hold a person's contact details.
     */
    public function update(UpdateServiceProviderRequest $request, ServiceProvider $serviceProvider, #[CurrentUser] User $actor): ServiceProviderResource
    {
        DB::transaction(function () use ($request, $serviceProvider, $actor): void {
            $changes = [];
            $serviceProvider->fill($request->safe()->only([...ServiceProvider::PROFILE_FIELDS, ...ServiceProvider::LEGAL_FIELDS]));
            $changedFields = array_keys($serviceProvider->getDirty());

            if (in_array('name', $changedFields, true)) {
                $changes['name'] = ['from' => $serviceProvider->getOriginal('name'), 'to' => $serviceProvider->name];
            }

            $otherChangedFields = array_values(array_diff($changedFields, ['name']));
            sort($otherChangedFields);

            if ($otherChangedFields !== []) {
                $changes['fields'] = $otherChangedFields;
            }

            $serviceProvider->save();

            if ($request->safe()->has('sectors')) {
                $changes += $this->syncCodes('sectors', $serviceProvider->sectors(), Sector::query()->whereIn('code', $request->validated('sectors'))->pluck('id')->all());
            }

            if ($request->safe()->has('services')) {
                $previousServiceIds = $serviceProvider->services()->pluck('catalog_services.id')->all();
                $changes += $this->syncCodes('services', $serviceProvider->services(), CatalogService::query()->whereIn('code', $request->validated('services'))->pluck('id')->all());

                // A newly listed service starts pending (the column default) and waits
                // for IMC review (ADR-021); listings kept from before keep their status.
                $added = array_values(array_diff($serviceProvider->services()->pluck('catalog_services.id')->all(), $previousServiceIds));
                if ($added !== []) {
                    MarketplaceNotifications::serviceListingsSubmitted($serviceProvider, $added);
                }
            }

            if ($changes !== []) {
                AuditLog::record(AuditEvent::ServiceProviderUpdated, $actor, $serviceProvider, $changes);
            }
        });

        return new ServiceProviderResource($serviceProvider->load(self::PROFILE_RELATIONS));
    }

    /**
     * Replace a code-identified relation and describe the change for the audit log, or
     * return nothing when the list did not change.
     *
     * @param  BelongsToMany<covariant Sector|CatalogService, ServiceProvider>  $relation
     * @param  array<array-key, mixed>  $ids
     * @return array<string, array{from: array<array-key, mixed>, to: array<array-key, mixed>}>
     */
    private function syncCodes(string $key, BelongsToMany $relation, array $ids): array
    {
        $previousCodes = $relation->pluck('code')->all();
        $synced = $relation->sync($ids);

        if ($synced['attached'] === [] && $synced['detached'] === []) {
            return [];
        }

        return [$key => ['from' => $previousCodes, 'to' => $relation->pluck('code')->all()]];
    }
}
