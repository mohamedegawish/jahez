<?php

namespace App\Readiness;

use App\Enums\ProviderApprovalStatus;
use App\Enums\ReadinessCategoryCode;
use App\Enums\ServiceListingStatus;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\ReadinessAssessment;
use App\Models\ReadinessCategory;
use App\Models\ReadinessLevelService;
use App\Models\ReadinessLevelUnlock;
use App\Models\ServiceProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The one place that decides which catalog services and providers a factory may see and
 * request (ADR-025, owner brief 2026-10-05). Every factory-facing path uses it: the
 * catalog and its categories, the service listings, the provider directory (list,
 * profile and logo), the eligibility summary, service requests (creation and adding
 * providers) and transformation plans.
 *
 * A service is available to a factory when:
 * 1. the factory has a current readiness assessment (its latest, ADR-018; a
 *    self-assessment counts until expert validation is decided, OQ-08), and
 * 2. IMC made the service available, through an active readiness_level_services row, to
 *    the factory's level or any level below it (ADR-026: levels are cumulative). The
 *    catalog has no active flag of its own (A18).
 *
 * The factory's level is the highest of its current assessment's category and the levels
 * it opened by completing its plan's services of the level below (readiness_level_unlocks,
 * ADR-026). Without an assessment there is no level, whatever was opened before.
 *
 * A provider is eligible for a factory and an available service when, in addition, it is
 * approved, its listing of the service is approved (ADR-021) and it targets one of the
 * factory's sectors (ADR-014): ServiceProvider::eligibleFor() and offering(), which only
 * this class calls. Promotions only reorder what this allows; readiness recommendations
 * and `filter[recommended]` only narrow it.
 */
class ServiceEligibility
{
    /** The factory has a current assessment, so its level decides its services. */
    public const STATUS_ELIGIBLE = 'eligible';

    /** The factory has not completed the readiness assessment: no service is available. */
    public const STATUS_NO_ASSESSMENT = 'no_assessment';

    /** The factory's level is the category of its current assessment. */
    public const UNLOCKED_BY_ASSESSMENT = 'assessment';

    /** The factory opened its level by completing its plan's services of the level below. */
    public const UNLOCKED_BY_PLAN = 'plan_completion';

    /**
     * The factory's current readiness assessment with its category, or null before the
     * first one. Read once per factory instance and kept on it as the relation, so the
     * checks of one request agree with each other.
     */
    public function currentAssessment(Factory $factory): ?ReadinessAssessment
    {
        if (! $factory->relationLoaded('currentReadinessAssessment')) {
            $factory->setRelation('currentReadinessAssessment', $factory->currentReadinessAssessment()->with('category')->first());
        }

        $assessment = $factory->currentReadinessAssessment;
        $assessment?->loadMissing('category');

        return $assessment;
    }

    /**
     * The category code of the factory's current assessment, or null before the first one.
     */
    public function assessedLevelOf(Factory $factory): ?ReadinessCategoryCode
    {
        return $this->currentAssessment($factory)?->category?->code;
    }

    /**
     * The levels the factory opened through its plan (ADR-026), read once per factory
     * instance and kept on it as the relation.
     *
     * @return Collection<int, ReadinessLevelUnlock>
     */
    public function unlocksOf(Factory $factory): Collection
    {
        if (! $factory->relationLoaded('readinessLevelUnlocks')) {
            $factory->setRelation('readinessLevelUnlocks', $factory->readinessLevelUnlocks()->get());
        }

        return $factory->readinessLevelUnlocks;
    }

    /**
     * The factory's level: the highest of its current assessment's category and the levels
     * it opened through its plan; null before the first assessment.
     */
    public function levelOf(Factory $factory): ?ReadinessCategoryCode
    {
        $assessed = $this->assessedLevelOf($factory);

        return $assessed === null ? null : ReadinessCategoryCode::highest([
            $assessed,
            ...$this->unlocksOf($factory)->map(fn (ReadinessLevelUnlock $unlock): ReadinessCategoryCode => $unlock->level),
        ]);
    }

    public function statusOf(Factory $factory): string
    {
        return $this->levelOf($factory) === null ? self::STATUS_NO_ASSESSMENT : self::STATUS_ELIGIBLE;
    }

    /**
     * The ids of the catalog services active at the level or any level below it, as a
     * subquery. No level means no service.
     */
    public function serviceIdsUpToLevel(?ReadinessCategoryCode $level): QueryBuilder
    {
        return DB::table('readiness_level_services')
            ->select('catalog_service_id')
            ->where('is_active', true)
            ->when(
                $level,
                fn (QueryBuilder $query, ReadinessCategoryCode $level) => $query->whereIn('level', self::codes($level->atOrBelow())),
                fn (QueryBuilder $query) => $query->whereRaw('1 = 0'),
            );
    }

    /**
     * The ids of the catalog services available to the factory, as a subquery.
     */
    public function serviceIdsFor(Factory $factory): QueryBuilder
    {
        return $this->serviceIdsUpToLevel($this->levelOf($factory));
    }

    /**
     * The catalog services available to the factory, in catalog order.
     *
     * @return Builder<CatalogService>
     */
    public function servicesFor(Factory $factory): Builder
    {
        return CatalogService::query()
            ->whereIn('catalog_services.id', $this->serviceIdsFor($factory))
            ->orderBy('service_category_id')
            ->orderBy('sort_order');
    }

    /**
     * The ids of the catalog services available to the factory.
     *
     * @return array<int, int>
     */
    public function availableServiceIds(Factory $factory): array
    {
        return $this->servicesFor($factory)->get(['catalog_services.id'])->map(fn (CatalogService $service): int => $service->id)->all();
    }

    public function isServiceAvailable(Factory $factory, CatalogService $service): bool
    {
        return $this->servicesFor($factory)->whereKey($service->id)->exists();
    }

    /**
     * The same check inside the caller's transaction: the factory's current assessment and
     * opened levels are read again (not the copies kept on the model) and a matching level
     * row is share-locked, so IMC switching the service off at the same moment applies
     * before or after the caller's change, never in between. Opened levels are not
     * locked: opening a level only ever widens what is available. Lock order: the factory
     * row first, then these rows, then the providers.
     */
    public function lockServiceAvailability(Factory $factory, CatalogService $service): bool
    {
        $assessed = $factory->currentReadinessAssessment()->with('category')->first()?->category?->code;

        if ($assessed === null) {
            return false;
        }

        $level = ReadinessCategoryCode::highest([
            $assessed,
            ...$factory->readinessLevelUnlocks()->get()->map(fn (ReadinessLevelUnlock $unlock): ReadinessCategoryCode => $unlock->level),
        ]) ?? $assessed;

        return ReadinessLevelService::query()
            ->whereIn('level', self::codes($level->atOrBelow()))
            ->where('catalog_service_id', $service->id)
            ->where('is_active', true)
            ->sharedLock()
            ->first() !== null;
    }

    /**
     * The providers eligible for the factory and the service: none when the service is
     * not available to the factory's level.
     *
     * @return Builder<ServiceProvider>
     */
    public function providersFor(Factory $factory, CatalogService $service): Builder
    {
        $available = $this->isServiceAvailable($factory, $service);

        return ServiceProvider::query()
            ->eligibleFor($factory)
            ->offering($service)
            ->when(! $available, fn (Builder $query) => $query->whereRaw('1 = 0'));
    }

    /**
     * The providers found in the directory (list, profile and logo). A factory member finds
     * those eligible for the factory and offering, through an approved listing, at least
     * one service available to its level; anyone else (null) every approved provider.
     *
     * @return Builder<ServiceProvider>
     */
    public function directoryProvidersFor(?Factory $factory): Builder
    {
        if ($factory === null) {
            return ServiceProvider::query()->approved();
        }

        return ServiceProvider::query()
            ->eligibleFor($factory)
            ->whereHas('approvedServices', fn (Builder $services) => $services->whereIn('catalog_services.id', $this->serviceIdsFor($factory)));
    }

    /**
     * Narrow a query over `catalog_service_service_provider` (aliased `$alias`) to the
     * listings the factory may see: approved listings, of eligible providers, for services
     * available to its level.
     */
    public function constrainListings(QueryBuilder $listings, Factory $factory, string $alias = 'l'): QueryBuilder
    {
        return $listings
            ->where("{$alias}.status", ServiceListingStatus::Approved->value)
            ->whereIn("{$alias}.service_provider_id", ServiceProvider::query()->eligibleFor($factory)->select('id')->toBase())
            ->whereIn("{$alias}.catalog_service_id", $this->serviceIdsFor($factory));
    }

    /**
     * The number of providers eligible for the factory per available service.
     *
     * @param  array<int|string>  $serviceIds
     * @return array<int, int>
     */
    public function eligibleProviderCounts(Factory $factory, array $serviceIds): array
    {
        if ($serviceIds === []) {
            return [];
        }

        return DB::table('catalog_service_service_provider as l')
            ->whereIn('l.catalog_service_id', $serviceIds)
            ->where('l.status', ServiceListingStatus::Approved->value)
            ->whereIn('l.service_provider_id', ServiceProvider::query()->eligibleFor($factory)->select('id')->toBase())
            ->groupBy('l.catalog_service_id')
            ->selectRaw('l.catalog_service_id, COUNT(*) AS providers')
            ->pluck('providers', 'catalog_service_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * The number of approved providers with an approved listing per catalog service,
     * whatever their sectors: what IMC sees when deciding a level's services (a factory's
     * own count also depends on its sectors).
     *
     * @return array<int, int>
     */
    public function approvedProviderCounts(): array
    {
        return DB::table('catalog_service_service_provider as l')
            ->join('service_providers as p', 'p.id', '=', 'l.service_provider_id')
            ->where('l.status', ServiceListingStatus::Approved->value)
            ->where('p.approval_status', ProviderApprovalStatus::Approved->value)
            ->groupBy('l.catalog_service_id')
            ->selectRaw('l.catalog_service_id, COUNT(*) AS providers')
            ->pluck('providers', 'catalog_service_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * The factory's level as shown to everyone who may see the factory: the code and names
     * of the level and whether the assessment or the plan opened it (ADR-026); null before
     * the first assessment. The names are those of the assessed questionnaire version.
     *
     * @return array{code: string, name_ar: string|null, name_en: string|null, unlocked_by: string, unlocked_at: string|null}|null
     */
    public function levelFor(Factory $factory): ?array
    {
        $assessment = $this->currentAssessment($factory);
        $level = $this->levelOf($factory);

        if ($assessment === null || $level === null) {
            return null;
        }

        $unlock = $level === $assessment->category?->code
            ? null
            : $this->unlocksOf($factory)->first(fn (ReadinessLevelUnlock $unlock): bool => $unlock->level === $level);
        $category = $unlock === null
            ? $assessment->category
            : ReadinessCategory::query()->where('readiness_questionnaire_id', $assessment->readiness_questionnaire_id)->where('code', $level->value)->first();

        return [
            'code' => $level->value,
            'name_ar' => $category?->name_ar,
            'name_en' => $category?->name_en,
            'unlocked_by' => $unlock === null ? self::UNLOCKED_BY_ASSESSMENT : self::UNLOCKED_BY_PLAN,
            'unlocked_at' => $unlock?->unlocked_at->toIso8601ZuluString(),
        ];
    }

    /**
     * The factory's readiness for its own pages and for IMC: its level (as levelFor()),
     * the category its current assessment gave it and, for IMC only ($withScore), the
     * assessment's total. A factory member never receives a score (owner decision,
     * 2026-10-06); null before the first assessment.
     *
     * @return array{assessment_id: int, level: string, name_ar: string|null, unlocked_by: string, unlocked_at: string|null, assessed_level: string|null, assessed_name_ar: string|null, total_score?: int, completed_at: string}|null
     */
    public function readinessFor(Factory $factory, bool $withScore): ?array
    {
        $assessment = $this->currentAssessment($factory);
        $level = $this->levelFor($factory);

        if ($assessment === null || $level === null) {
            return null;
        }

        return [
            'assessment_id' => $assessment->id,
            'level' => $level['code'],
            'name_ar' => $level['name_ar'],
            'unlocked_by' => $level['unlocked_by'],
            'unlocked_at' => $level['unlocked_at'],
            'assessed_level' => $assessment->category?->code->value,
            'assessed_name_ar' => $assessment->category?->name_ar,
            ...($withScore ? ['total_score' => $assessment->total_score] : []),
            'completed_at' => $assessment->completed_at->toIso8601ZuluString(),
        ];
    }

    /**
     * What the factory may use, for its services page and for IMC building its plan: the
     * status, the current readiness (the score for IMC only, $withScore), and every service
     * available to its level with the number of providers eligible for it (zero is shown,
     * never hidden).
     *
     * @return array{status: string, has_sectors: bool, readiness: array<string, mixed>|null, services: array<int, array{service: CatalogService, eligible_providers_count: int, recommended: bool}>}
     */
    public function summary(Factory $factory, bool $withScore = false): array
    {
        $assessment = $this->currentAssessment($factory);
        $services = $this->servicesFor($factory)->with('category')->get()->values();
        $counts = $this->eligibleProviderCounts($factory, $services->modelKeys());
        $recommended = $assessment === null ? [] : $factory->recommendedCatalogServices()->pluck('catalog_services.id')->all();

        return [
            'status' => $assessment === null ? self::STATUS_NO_ASSESSMENT : self::STATUS_ELIGIBLE,
            'has_sectors' => $factory->sectors()->exists(),
            'readiness' => $this->readinessFor($factory, $withScore),
            'services' => $services->map(fn (CatalogService $service): array => [
                'service' => $service,
                'eligible_providers_count' => $counts[$service->id] ?? 0,
                'recommended' => in_array($service->id, $recommended, true),
            ])->all(),
        ];
    }

    /**
     * @param  list<ReadinessCategoryCode>  $levels
     * @return list<string>
     */
    private static function codes(array $levels): array
    {
        return array_map(fn (ReadinessCategoryCode $level): string => $level->value, $levels);
    }
}
