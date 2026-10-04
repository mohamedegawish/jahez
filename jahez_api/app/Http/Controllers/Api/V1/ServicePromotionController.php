<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreServicePromotionRequest;
use App\Http\Resources\V1\ServicePromotionResource;
use App\Models\AuditLog;
use App\Models\ServicePromotion;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * IMC promotions of provider listings (ADR-020). A promotion is labelled «إعلان» where
 * factories see the listing, and is ordered by priority before ordinary listings. It is
 * never deleted: ending it keeps the record.
 */
class ServicePromotionController extends Controller
{
    private const RELATIONS = ['serviceProvider', 'service.category', 'createdBy'];

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ServicePromotion::class);

        $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'filter' => ['sometimes', 'array:state'],
            'filter.state' => ['sometimes', 'string', Rule::in(['running', 'ended'])],
        ]);

        $promotions = ServicePromotion::query()
            ->when($request->input('filter.state') === 'running', fn ($query) => $query->running())
            ->when($request->input('filter.state') === 'ended', fn ($query) => $query->whereNotNull('ended_at'))
            ->with(self::RELATIONS)
            ->orderByRaw('ended_at IS NULL DESC')
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return ServicePromotionResource::collection($promotions);
    }

    public function store(StoreServicePromotionRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $service = $request->service();

        $promotion = DB::transaction(function () use ($request, $user, $service): ServicePromotion {
            $promotion = new ServicePromotion;
            $promotion->service_provider_id = $request->integer('service_provider_id');
            $promotion->catalog_service_id = $service->id;
            $promotion->headline = $request->filled('headline') ? $request->string('headline')->toString() : null;
            $promotion->priority = $request->integer('priority', 0);
            $promotion->starts_at = $request->filled('starts_at') ? $request->date('starts_at') ?? now() : now();
            $promotion->ends_at = $request->filled('ends_at') ? $request->date('ends_at') : null;
            $promotion->created_by_user_id = $user->id;
            $promotion->save();

            AuditLog::record(AuditEvent::PromotionCreated, $user, $promotion, [
                'service_provider_id' => $promotion->service_provider_id,
                'service' => $service->code,
                'priority' => $promotion->priority,
            ]);
            MarketplaceNotifications::promotionChanged($promotion, ended: false);

            return $promotion;
        });

        return (new ServicePromotionResource($promotion->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /**
     * Change the headline, priority or end date of a promotion that has not been ended.
     */
    public function update(Request $request, ServicePromotion $servicePromotion, #[CurrentUser] User $user): ServicePromotionResource
    {
        Gate::authorize('update', $servicePromotion);

        $validated = $request->validate([
            'headline' => ['sometimes', 'nullable', 'string', 'max:120'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:'.StoreServicePromotionRequest::MAX_PRIORITY],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        DB::transaction(function () use ($servicePromotion, $validated, $user): void {
            $locked = ServicePromotion::query()->lockForUpdate()->findOrFail($servicePromotion->id);

            if ($locked->ended_at !== null) {
                throw new ConflictHttpException('This promotion has been ended and can no longer be changed.');
            }

            if (array_key_exists('headline', $validated)) {
                $locked->headline = $validated['headline'];
            }
            if (array_key_exists('priority', $validated)) {
                $locked->priority = (int) $validated['priority'];
            }
            if (array_key_exists('ends_at', $validated)) {
                $locked->ends_at = $validated['ends_at'] === null ? null : Carbon::parse((string) $validated['ends_at']);
            }

            $changed = array_keys($locked->getDirty());
            $locked->save();

            if ($changed !== []) {
                AuditLog::record(AuditEvent::PromotionUpdated, $user, $locked, ['fields' => $changed]);
            }
        });

        return new ServicePromotionResource($servicePromotion->refresh()->load(self::RELATIONS));
    }

    public function end(ServicePromotion $servicePromotion, #[CurrentUser] User $user): ServicePromotionResource
    {
        Gate::authorize('update', $servicePromotion);

        DB::transaction(function () use ($servicePromotion, $user): void {
            $locked = ServicePromotion::query()->lockForUpdate()->findOrFail($servicePromotion->id);

            if ($locked->ended_at !== null) {
                throw new ConflictHttpException('This promotion has already been ended.');
            }

            $locked->ended_at = now();
            $locked->ended_by_user_id = $user->id;
            $locked->save();

            AuditLog::record(AuditEvent::PromotionEnded, $user, $locked);
            MarketplaceNotifications::promotionChanged($locked, ended: true);
        });

        return new ServicePromotionResource($servicePromotion->refresh()->load(self::RELATIONS));
    }
}
