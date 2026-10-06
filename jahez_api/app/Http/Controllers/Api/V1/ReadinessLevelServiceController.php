<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\ReadinessCategoryCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateReadinessLevelServiceRequest;
use App\Http\Resources\V1\CatalogServiceResource;
use App\Models\AuditLog;
use App\Models\CatalogService;
use App\Models\ReadinessCategory;
use App\Models\ReadinessLevelService;
use App\Models\ReadinessQuestionnaire;
use App\Models\ReadinessRecommendation;
use App\Models\User;
use App\Readiness\ServiceEligibility;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Which catalog services each digital readiness level makes available (ADR-025). IMC
 * decides every row; nothing is seeded (owner decision 2026-10-05). A service may be
 * available to several levels; switching it off or removing it from a level never
 * deletes the catalog service. Each change is audited in its transaction.
 */
class ReadinessLevelServiceController extends Controller
{
    public function __construct(private readonly ServiceEligibility $eligibility) {}

    /**
     * The four levels as the current questionnaire version defines them, each with its
     * services (active or not), the number of approved providers with an approved listing
     * of each service, and the services the level's readiness roadmap recommends (a hint,
     * never an availability). The summary lists the catalog services no level makes
     * available, those several levels share, and active ones no provider offers yet.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', ReadinessLevelService::class);

        $categories = ReadinessQuestionnaire::current()?->categories()->with('recommendations.services')->get()->keyBy(fn (ReadinessCategory $category): string => $category->code->value) ?? collect();
        $rows = ReadinessLevelService::query()->with(['service.category'])->get();
        $providerCounts = $this->eligibility->approvedProviderCounts();
        $catalog = CatalogService::query()->with('category')->orderBy('service_category_id')->orderBy('sort_order')->get();

        $levels = [];

        foreach (ReadinessCategoryCode::cases() as $code) {
            $category = $categories->get($code->value);
            $recommended = $category === null ? [] : $category->recommendations
                ->flatMap(fn (ReadinessRecommendation $recommendation) => $recommendation->services->modelKeys())
                ->unique()->values()->all();

            $levels[] = [
                'code' => $code->value,
                'name_ar' => $category?->name_ar,
                'name_en' => $category?->name_en,
                'min_score' => $category?->min_score,
                'max_score' => $category?->max_score,
                'recommended_service_ids' => $recommended,
                'services' => $rows->where('level', $code)
                    ->sortBy(fn (ReadinessLevelService $row): string => sprintf('%05d-%05d', $row->service->service_category_id, $row->service->sort_order))
                    ->map(fn (ReadinessLevelService $row): array => [
                        'service' => new CatalogServiceResource($row->service),
                        'is_active' => $row->is_active,
                        'approved_provider_count' => $providerCounts[$row->catalog_service_id] ?? 0,
                        'recommended' => in_array($row->catalog_service_id, $recommended, true),
                        'updated_at' => $row->updated_at?->toIso8601ZuluString(),
                    ])->values()->all(),
            ];
        }

        $active = $rows->where('is_active', true);
        $levelsPerService = $active->countBy('catalog_service_id');

        return response()->json([
            'data' => [
                'levels' => $levels,
                'summary' => [
                    'catalog_service_count' => $catalog->count(),
                    'unassigned_services' => CatalogServiceResource::collection($catalog->reject(fn (CatalogService $service): bool => $levelsPerService->has($service->id))->values()),
                    'multi_level_service_ids' => $levelsPerService->filter(fn (int $count): bool => $count > 1)->keys()->map(fn (int|string $id): int => (int) $id)->values()->all(),
                    'without_provider_service_ids' => $active->pluck('catalog_service_id')->unique()->reject(fn (int $id): bool => ($providerCounts[$id] ?? 0) > 0)->values()->all(),
                ],
            ],
        ]);
    }

    /**
     * Make the service available to the level, or switch it on or off there. Repeating the
     * same call changes nothing and records nothing.
     */
    public function update(UpdateReadinessLevelServiceRequest $request, string $level, CatalogService $catalogService, #[CurrentUser] User $user): JsonResponse
    {
        $code = ReadinessCategoryCode::from($level);
        $active = $request->isActive();

        $row = DB::transaction(function () use ($code, $catalogService, $active, $user): ReadinessLevelService {
            $row = ReadinessLevelService::query()->where('level', $code)->where('catalog_service_id', $catalogService->id)->lockForUpdate()->first();

            if ($row === null) {
                $row = new ReadinessLevelService;
                $row->level = $code;
                $row->catalog_service_id = $catalogService->id;
                $row->is_active = $active;
                $row->created_by_user_id = $user->id;
                $row->updated_by_user_id = $user->id;
                $row->save();

                AuditLog::record(AuditEvent::ReadinessLevelServiceAssigned, $user, $catalogService, [
                    'level' => $code->value,
                    'service' => $catalogService->code,
                    'is_active' => $active,
                ]);

                return $row;
            }

            if ($row->is_active !== $active) {
                $row->is_active = $active;
                $row->updated_by_user_id = $user->id;
                $row->save();

                AuditLog::record(AuditEvent::ReadinessLevelServiceUpdated, $user, $catalogService, [
                    'level' => $code->value,
                    'service' => $catalogService->code,
                    'is_active' => ['from' => ! $active, 'to' => $active],
                ]);
            }

            return $row;
        });

        return response()->json(['data' => [
            'level' => $code->value,
            'service' => new CatalogServiceResource($catalogService->load('category')),
            'is_active' => $row->is_active,
            'approved_provider_count' => $this->eligibility->approvedProviderCounts()[$catalogService->id] ?? 0,
        ]], $row->wasRecentlyCreated ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK);
    }

    /**
     * Remove the service from the level. The catalog service itself is untouched; requests
     * and plans already made keep their history.
     */
    public function destroy(string $level, CatalogService $catalogService, #[CurrentUser] User $user): Response
    {
        Gate::authorize('manage', ReadinessLevelService::class);
        $code = ReadinessCategoryCode::from($level);

        DB::transaction(function () use ($code, $catalogService, $user): void {
            $row = ReadinessLevelService::query()->where('level', $code)->where('catalog_service_id', $catalogService->id)->lockForUpdate()->firstOrFail();
            $row->delete();

            AuditLog::record(AuditEvent::ReadinessLevelServiceRemoved, $user, $catalogService, [
                'level' => $code->value,
                'service' => $catalogService->code,
                'was_active' => $row->is_active,
            ]);
        });

        return response()->noContent();
    }
}
