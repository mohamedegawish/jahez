<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\DocumentType;
use App\Enums\FactoryApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListFactoriesRequest;
use App\Http\Requests\Api\V1\StoreFactoryRequest;
use App\Http\Requests\Api\V1\UpdateFactoryRequest;
use App\Http\Resources\V1\FactoryResource;
use App\Http\Resources\V1\FactorySummaryResource;
use App\Models\AuditLog;
use App\Models\Factory;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class FactoryController extends Controller
{
    /**
     * Relations the factory resource shows.
     */
    public const RELATIONS = ['sectors', 'activeDocuments', 'currentReadinessAssessment.questionnaire', 'currentReadinessAssessment.category'];

    /**
     * Relations of the list card (FactorySummaryResource): only the logo document.
     */
    private const SUMMARY_RELATIONS = ['sectors', 'currentReadinessAssessment.questionnaire', 'currentReadinessAssessment.category'];

    public function index(ListFactoriesRequest $request): AnonymousResourceCollection
    {
        $factories = Factory::query()
            ->when($request->input('filter.sector'), fn (Builder $query, string $code) => $query->whereHas(
                'sectors',
                fn (Builder $sectors) => $sectors->where('code', $code),
            ))
            ->when($request->input('filter.size'), fn (Builder $query, string $size) => $query->where('size', $size))
            ->when($request->input('filter.approval_status'), fn (Builder $query, string $status) => $query->where('approval_status', $status))
            ->when($request->input('filter.readiness'), fn (Builder $query, string $readiness) => $readiness === 'none'
                ? $query->whereDoesntHave('readinessAssessments')
                : $query->whereHas('currentReadinessAssessment.category', fn (Builder $categories) => $categories->where('code', $readiness)))
            ->when($request->input('search'), fn (Builder $query, string $term) => $query->nameContains($term))
            ->with([...self::SUMMARY_RELATIONS, 'activeDocuments' => fn ($documents) => $documents->where('type', DocumentType::Logo)])
            ->withCount('serviceRequests')
            ->when($request->sortColumnAndDirection(), fn (Builder $query, array $sort) => $query->orderBy($sort[0], $sort[1]))
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return FactorySummaryResource::collection($factories);
    }

    public function store(StoreFactoryRequest $request, #[CurrentUser] User $actor): JsonResponse
    {
        $factory = DB::transaction(function () use ($request, $actor): Factory {
            $factory = new Factory($request->safe()->only(['name', 'size', ...Factory::PROFILE_FIELDS]));
            // IMC created the account itself, so there is nothing to review (ADR-021).
            $factory->approval_status = FactoryApprovalStatus::Approved;
            $factory->approval_changed_at = now();
            $factory->save();
            $sectorCodes = $request->validated('sectors', []);
            $factory->sectors()->sync($this->sectorIds($sectorCodes));

            AuditLog::record(AuditEvent::FactoryCreated, $actor, $factory, ['name' => $factory->name, ...($factory->size !== null ? ['size' => $factory->size] : []), 'sectors' => $sectorCodes]);

            return $factory;
        });

        return (new FactoryResource($factory->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Factory $factory): FactoryResource
    {
        Gate::authorize('view', $factory);

        return new FactoryResource($factory->load(self::RELATIONS));
    }

    public function update(UpdateFactoryRequest $request, Factory $factory, #[CurrentUser] User $actor): FactoryResource
    {
        DB::transaction(function () use ($request, $factory, $actor): void {
            $changes = [];

            if ($request->safe()->has('name') && $factory->name !== $request->string('name')->toString()) {
                $changes['name'] = ['from' => $factory->name, 'to' => $request->string('name')->toString()];
                $factory->update($request->safe()->only(['name']));
            }

            if ($request->safe()->has('size') && $factory->size !== $request->validated('size')) {
                $changes['size'] = ['from' => $factory->size, 'to' => $request->validated('size')];
                $factory->update(['size' => $request->validated('size')]);
            }

            // Registration details: only the names of the changed fields are recorded,
            // because they hold a person's contact details (as for providers).
            $factory->fill($request->safe()->only(Factory::PROFILE_FIELDS));
            $changedFields = array_keys($factory->getDirty());
            sort($changedFields);

            if ($changedFields !== []) {
                $changes['fields'] = $changedFields;
                $factory->save();
            }

            if ($request->safe()->has('sectors')) {
                $previousSectorCodes = $factory->sectors()->pluck('code')->all();
                $synced = $factory->sectors()->sync($this->sectorIds($request->validated('sectors')));

                if ($synced['attached'] !== [] || $synced['detached'] !== []) {
                    $changes['sectors'] = ['from' => $previousSectorCodes, 'to' => $factory->sectors()->pluck('code')->all()];
                }
            }

            if ($changes !== []) {
                AuditLog::record(AuditEvent::FactoryUpdated, $actor, $factory, $changes);
            }
        });

        return new FactoryResource($factory->load(self::RELATIONS));
    }

    /**
     * @param  array<int, string>  $sectorCodes
     * @return Collection<int, mixed>
     */
    private function sectorIds(array $sectorCodes): Collection
    {
        return Sector::query()->whereIn('code', $sectorCodes)->pluck('id');
    }
}
