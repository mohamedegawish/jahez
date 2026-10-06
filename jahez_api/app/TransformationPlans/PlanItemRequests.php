<?php

namespace App\TransformationPlans;

use App\Enums\TransformationPlanStatus;
use App\Enums\TransformationPlanVersionStatus;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\ServiceRequest;
use App\Models\TransformationPlan;
use App\Models\TransformationPlanItem;
use App\Models\TransformationPlanStageItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The link between a service request and a transformation plan item (ADR-025). A factory
 * member may send a request for an item of its factory's published plan, for the item's
 * service. Sending it never starts the item (owner decision 2026-10-05: only IMC records
 * the start, once the prerequisites are completed and the agreement is approved), and it
 * may be sent before the prerequisites are completed (owner decision). When IMC assigned a
 * provider to the item, the request goes to that provider only (binding, owner decision).
 * At most one live request per item: a cancelled request, or one whose agreement IMC
 * rejected, frees the item for a new one.
 */
class PlanItemRequests
{
    public const NOT_IN_PLAN = 'The plan item was not found in your factory\'s published transformation plan.';

    public const OTHER_SERVICE = 'The plan item is for another service.';

    public const ASSIGNED_PROVIDER = 'IMC assigned a provider to this plan item: send the request to that provider only.';

    /**
     * Problems found before the transaction (field => message); none means the link is
     * acceptable so far. The controller checks again under lock.
     *
     * @param  list<int>  $providerIds
     * @return array<string, string>
     */
    public function problems(Factory $factory, CatalogService $service, int $itemId, array $providerIds): array
    {
        $placement = $this->publishedPlacement($factory, $itemId);

        if ($placement === null) {
            return ['transformation_plan_item_id' => self::NOT_IN_PLAN];
        }

        if ($placement->item->catalog_service_id !== $service->id) {
            return ['transformation_plan_item_id' => self::OTHER_SERVICE];
        }

        if ($placement->service_provider_id !== null && $providerIds !== [$placement->service_provider_id]) {
            return ['provider_ids' => self::ASSIGNED_PROVIDER];
        }

        return [];
    }

    /**
     * Inside the request's transaction, after the factory row: share-lock the plan, lock
     * the item, check everything again and refuse a second live request with 409.
     *
     * @param  list<int>  $providerIds
     */
    public function lockForNewRequest(Factory $factory, CatalogService $service, int $itemId, array $providerIds): TransformationPlanItem
    {
        $planId = TransformationPlanItem::query()->whereKey($itemId)->value('transformation_plan_id');
        $plan = $planId === null ? null : TransformationPlan::query()->whereKey($planId)->sharedLock()->first();

        if ($plan === null || $plan->factory_id !== $factory->id) {
            throw ValidationException::withMessages(['transformation_plan_item_id' => self::NOT_IN_PLAN]);
        }

        $item = TransformationPlanItem::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();
        $problems = $this->problems($factory, $service, $itemId, $providerIds);

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        if ($plan->status !== TransformationPlanStatus::Published) {
            throw new ConflictHttpException("The transformation plan is {$plan->status->value}: requests for its items are paused.");
        }

        if ($item->execution_status->isClosed()) {
            throw new ConflictHttpException("This plan item is {$item->execution_status->value} and takes no new request.");
        }

        $live = RequestProgress::latestLive($item->serviceRequests()->with(RequestProgress::RELATIONS)->get());

        if ($live !== null) {
            throw new ConflictHttpException("Service request {$live->id} for this plan item is still {$live->status->value}; cancel it before sending another.");
        }

        return $item;
    }

    /**
     * For a request linked to a plan item, the providers the factory may still add: only
     * the provider IMC assigned in the published version, if any. Returns the problem, or
     * null.
     *
     * @param  list<int>  $providerIds
     */
    public function addedProvidersProblem(ServiceRequest $request, array $providerIds): ?string
    {
        if ($request->transformation_plan_item_id === null) {
            return null;
        }

        $placement = $this->publishedPlacement($request->industrialFactory()->firstOrFail(), $request->transformation_plan_item_id);

        if ($placement?->service_provider_id !== null && $providerIds !== [$placement->service_provider_id]) {
            return self::ASSIGNED_PROVIDER;
        }

        return null;
    }

    /**
     * The item's place in the factory's published version, or null.
     */
    public function publishedPlacement(Factory $factory, int $itemId): ?TransformationPlanStageItem
    {
        return TransformationPlanStageItem::query()
            ->where('transformation_plan_item_id', $itemId)
            ->whereHas('version', fn (Builder $versions) => $versions
                ->where('status', TransformationPlanVersionStatus::Published)
                ->whereHas('plan', fn (Builder $plans) => $plans->where('factory_id', $factory->id)))
            ->with('item')
            ->first();
    }
}
