<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentType;
use App\Enums\Permission;
use App\Enums\ServiceListingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListServiceListingsRequest;
use App\Http\Resources\V1\ServiceListingResource;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\OrganizationDocument;
use App\Models\ServiceCategory;
use App\Models\ServicePromotion;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Service listings (ADR-020): one per provider and catalog service it offers.
 *
 * - A factory member sees the listings it is eligible for: listings IMC approved
 *   (ADR-021), of approved providers that target one of its sectors
 *   (ServiceProvider::eligibleFor()). `filter[recommended]` narrows them to its
 *   readiness roadmap's services and never widens eligibility.
 * - A provider member sees its own listings, with their review status.
 * - IMC administrators see every listing, with its review status
 *   (`filter[listing_status]`, `sort=newest` for the review queue).
 *
 * Listings with a running IMC promotion come first, by priority, then the catalog order.
 * A promotion never adds a listing the caller could not otherwise see, and a listing
 * appears once whether or not it is promoted.
 */
class ServiceListingController extends Controller
{
    public function index(ListServiceListingsRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        $factory = $user->factory_id !== null ? Factory::query()->findOrFail($user->factory_id) : null;
        $search = $request->filled('search') ? '%'.addcslashes($request->string('search')->toString(), '%_\\').'%' : null;

        $running = ServicePromotion::query()->running()
            ->selectRaw('service_provider_id, catalog_service_id, MAX(priority) AS promotion_priority')
            ->groupBy('service_provider_id', 'catalog_service_id');

        /** @var LengthAwarePaginator<int, object{service_provider_id: int, catalog_service_id: int, status: string, status_reason: string|null, status_changed_at: string|null, submitted_at: string|null, promotion_priority: int|null}> $page */
        $page = DB::table('catalog_service_service_provider as l')
            ->join('service_providers as p', 'p.id', '=', 'l.service_provider_id')
            ->join('catalog_services as s', 's.id', '=', 'l.catalog_service_id')
            ->leftJoinSub($running->toBase(), 'promo', fn ($join) => $join
                ->on('promo.service_provider_id', '=', 'l.service_provider_id')
                ->on('promo.catalog_service_id', '=', 'l.catalog_service_id'))
            ->select(['l.service_provider_id', 'l.catalog_service_id', 'l.status', 'l.status_reason', 'l.status_changed_at', 'l.submitted_at', 'promo.promotion_priority'])
            ->when($factory, fn (QueryBuilder $query, Factory $factory) => $query
                ->where('l.status', ServiceListingStatus::Approved->value)
                ->whereIn('l.service_provider_id', ServiceProvider::query()->eligibleFor($factory)->select('id')->toBase()))
            ->when($user->service_provider_id !== null, fn (QueryBuilder $query) => $query->where('l.service_provider_id', $user->service_provider_id))
            ->when($factory === null ? $request->input('filter.listing_status') : null, fn (QueryBuilder $query, string $status) => $query->where('l.status', $status))
            ->when($factory === null && $user->service_provider_id === null && $request->input('filter.approval_status'), fn (QueryBuilder $query) => $query->where('p.approval_status', $request->input('filter.approval_status')))
            ->when($factory === null && $user->service_provider_id === null && $request->input('filter.provider'), fn (QueryBuilder $query) => $query->where('l.service_provider_id', $request->integer('filter.provider')))
            ->when($request->input('filter.category'), fn (QueryBuilder $query, string $code) => $query->whereIn(
                's.service_category_id',
                ServiceCategory::query()->where('code', $code)->select('id')->toBase(),
            ))
            ->when($request->input('filter.service'), fn (QueryBuilder $query, string $code) => $query->where('s.code', $code))
            ->when($request->boolean('filter.recommended') ? $factory : null, fn (QueryBuilder $query, Factory $factory) => $query->whereIn(
                'l.catalog_service_id',
                $factory->recommendedCatalogServices()->select('catalog_services.id')->toBase(),
            ))
            ->when($request->boolean('filter.promoted'), fn (QueryBuilder $query) => $query->whereNotNull('promo.promotion_priority'))
            ->when($search, fn (QueryBuilder $query, string $search) => $query->where(fn (QueryBuilder $any) => $any
                ->whereLike('p.name', $search)
                ->orWhereLike('s.name_ar', $search)
                ->orWhereLike('s.code', $search)))
            ->when($request->input('sort') === 'newest', fn (QueryBuilder $query) => $query->orderByDesc('l.submitted_at'))
            ->orderByRaw('promo.promotion_priority IS NULL')
            ->orderByDesc('promo.promotion_priority')
            ->orderBy('s.service_category_id')
            ->orderBy('s.sort_order')
            ->orderBy('p.name')
            ->orderBy('p.id')
            ->paginate($request->perPage())
            ->withQueryString();

        $rows = collect($page->items());
        $providers = ServiceProvider::query()->whereKey($rows->pluck('service_provider_id')->unique()->all())->get()->keyBy('id');
        $services = CatalogService::query()->with('category')->whereKey($rows->pluck('catalog_service_id')->unique()->all())->get()->keyBy('id');
        $logos = OrganizationDocument::query()
            ->whereIn('service_provider_id', $providers->keys()->all())
            ->where('type', DocumentType::Logo)
            ->where('status', 'active')
            ->pluck('id', 'service_provider_id');
        $promotions = ServicePromotion::query()->running()
            ->whereIn('service_provider_id', $providers->keys()->all())
            ->whereIn('catalog_service_id', $services->keys()->all())
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (ServicePromotion $promotion): string => "{$promotion->service_provider_id}-{$promotion->catalog_service_id}")
            ->keyBy(fn (ServicePromotion $promotion): string => "{$promotion->service_provider_id}-{$promotion->catalog_service_id}");
        $recommended = $factory !== null
            ? $factory->recommendedCatalogServices()->pluck('catalog_services.id')->all()
            : [];

        $page->setCollection($rows->map(fn (object $row): array => [
            'provider' => $providers->get($row->service_provider_id),
            'service' => $services->get($row->catalog_service_id),
            'review' => [
                'status' => $row->status,
                'reason' => $row->status_reason,
                'changed_at' => $row->status_changed_at,
                'submitted_at' => $row->submitted_at,
            ],
            'promotion' => $promotions->get("{$row->service_provider_id}-{$row->catalog_service_id}"),
            'logo_document_id' => $logos->get($row->service_provider_id),
            'recommended' => $factory !== null ? in_array($row->catalog_service_id, $recommended, true) : null,
            'viewer' => match (true) {
                $factory !== null => 'factory',
                $user->service_provider_id !== null => 'provider',
                default => 'imc',
            },
        ]));

        return ServiceListingResource::collection($page)->additional(['meta' => $this->viewerMeta($user, $factory)]);
    }

    /**
     * What a factory member needs to understand an empty or narrow list: whether it has
     * sectors (no provider is eligible without one) and its readiness classification.
     *
     * @return array<string, mixed>
     */
    private function viewerMeta(User $user, ?Factory $factory): array
    {
        if ($factory === null) {
            return ['viewer' => $user->service_provider_id !== null ? 'provider' : ($user->hasPermission(Permission::PromotionsManage) ? 'imc' : 'other')];
        }

        $assessment = $factory->currentReadinessAssessment()->with('category')->first();

        return [
            'viewer' => 'factory',
            'has_sectors' => $factory->sectors()->exists(),
            'readiness' => $assessment === null ? null : [
                'category' => $assessment->category === null ? null : ['code' => $assessment->category->code, 'name_ar' => $assessment->category->name_ar],
                'total_score' => $assessment->total_score,
                'completed_at' => $assessment->completed_at->toIso8601ZuluString(),
            ],
        ];
    }
}
