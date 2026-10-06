<?php

namespace App\TransformationPlans;

use App\Models\Factory;
use App\Models\ServiceRequest;
use App\Models\TransformationPlan;
use App\Models\TransformationPlanDependency;
use App\Models\TransformationPlanItem;
use App\Models\TransformationPlanStage;
use App\Models\TransformationPlanStageItem;
use App\Models\TransformationPlanVersion;
use App\Readiness\ServiceEligibility;

/**
 * What IMC must fix before a plan version is published, and what it should know
 * (ADR-025). A blocking problem refuses the publication; a warning is shown and recorded.
 *
 * Blocking: the factory has no readiness assessment; the version has no stage, or a stage
 * has no service; a service is not available to the factory's current level (unless the
 * item is already underway); an assigned provider is not eligible; the dependencies form
 * a cycle; the version drops an item that has started or has a live request.
 *
 * Warnings: the factory's level changed since the plan's last publication; no provider is
 * eligible for a service yet; the factory's account is not approved, so it cannot send
 * requests (OQ-46).
 */
class TransformationPlanReview
{
    public function __construct(private readonly ServiceEligibility $eligibility) {}

    /**
     * @return list<array{code: string, blocking: bool, message: string, stage_position: int|null, service_code: string|null}>
     */
    public function problems(TransformationPlan $plan, TransformationPlanVersion $version): array
    {
        $factory = Factory::query()->findOrFail($plan->factory_id);
        $assessment = $this->eligibility->currentAssessment($factory);
        $problems = [];

        if ($assessment === null) {
            $problems[] = self::problem('no_assessment', true, 'The factory has no readiness assessment, so no service is available to it.');
        } elseif ($plan->based_on_readiness_assessment_id !== null && $plan->based_on_readiness_assessment_id !== $assessment->id) {
            $problems[] = self::problem('readiness_changed', false, 'The factory submitted a new readiness assessment after this plan was last published; check the plan against its current level.');
        }

        if (! $factory->maySendServiceRequests()) {
            $problems[] = self::problem('factory_not_approved', false, 'The factory\'s account is not approved, so it cannot send service requests for the plan yet.');
        }

        $stages = TransformationPlanStage::query()
            ->where('transformation_plan_version_id', $version->id)
            ->with(['stageItems.item.service'])
            ->orderBy('position')
            ->get();

        if ($stages->isEmpty()) {
            $problems[] = self::problem('no_stages', true, 'The plan needs at least one stage.');
        }

        $available = $this->eligibility->availableServiceIds($factory);

        foreach ($stages as $stage) {
            if ($stage->stageItems->isEmpty()) {
                $problems[] = self::problem('empty_stage', true, "Stage {$stage->position} has no service.", $stage->position);
            }

            foreach ($stage->stageItems as $stageItem) {
                $item = $stageItem->item;
                $service = $item->service;

                if (! in_array($service->id, $available, true)) {
                    $underway = $item->execution_status->isUnderway();
                    $problems[] = self::problem('service_not_available', ! $underway, "«{$service->name_ar}» is not available to the factory's current readiness level.", $stage->position, $service->code);

                    continue;
                }

                $eligible = $this->eligibility->providersFor($factory, $service);

                if ($stageItem->service_provider_id !== null && ! (clone $eligible)->whereKey($stageItem->service_provider_id)->exists()) {
                    $problems[] = self::problem('provider_not_eligible', true, "The provider assigned to «{$service->name_ar}» is not eligible for this factory and service.", $stage->position, $service->code);
                } elseif (! $eligible->exists()) {
                    $problems[] = self::problem('no_eligible_provider', false, "No provider is eligible for «{$service->name_ar}» for this factory yet.", $stage->position, $service->code);
                }
            }
        }

        if ($this->graphOf($version)->findCycle() !== null) {
            $problems[] = self::problem('dependency_cycle', true, 'The dependencies form a cycle.');
        }

        return [...$problems, ...$this->droppedItemsInUse($plan, $version)];
    }

    /**
     * Whether any problem blocks the publication.
     *
     * @param  list<array{code: string, blocking: bool, message: string, stage_position: int|null, service_code: string|null}>  $problems
     */
    public static function blocks(array $problems): bool
    {
        return collect($problems)->contains(fn (array $problem): bool => $problem['blocking']);
    }

    /**
     * The version's dependency graph over its placements.
     */
    public function graphOf(TransformationPlanVersion $version): DependencyGraph
    {
        $nodes = TransformationPlanStageItem::query()->where('transformation_plan_version_id', $version->id)->get(['id'])->map(fn (TransformationPlanStageItem $placement): int => $placement->id)->all();
        $edges = [];

        foreach (TransformationPlanDependency::query()->where('transformation_plan_version_id', $version->id)->get() as $dependency) {
            $edges[$dependency->stage_item_id][] = $dependency->depends_on_stage_item_id;
        }

        return new DependencyGraph($nodes, $edges);
    }

    /**
     * Items the published version places that this version drops, while the factory relies
     * on them: they started, or a request for them is live.
     *
     * @return list<array{code: string, blocking: bool, message: string, stage_position: int|null, service_code: string|null}>
     */
    private function droppedItemsInUse(TransformationPlan $plan, TransformationPlanVersion $version): array
    {
        $published = $plan->publishedVersion()->first();

        if ($published === null || $published->id === $version->id) {
            return [];
        }

        $kept = TransformationPlanStageItem::query()->where('transformation_plan_version_id', $version->id)->pluck('transformation_plan_item_id')->all();
        $dropped = TransformationPlanItem::query()
            ->whereIn('id', TransformationPlanStageItem::query()->where('transformation_plan_version_id', $published->id)->select('transformation_plan_item_id'))
            ->whereNotIn('id', $kept)
            ->with(['service', 'serviceRequests' => fn ($requests) => $requests->with(RequestProgress::RELATIONS)])
            ->get();

        $problems = [];

        foreach ($dropped as $item) {
            $live = $item->serviceRequests->contains(fn (ServiceRequest $request): bool => RequestProgress::of($request)->blocksNewRequest());

            if ($live || $item->execution_status->isUnderway()) {
                $problems[] = self::problem('removed_item_in_use', true, "«{$item->service->name_ar}» has started or has a live request; keep it in the plan or cancel it first.", null, $item->service->code);
            }
        }

        return $problems;
    }

    /**
     * @return array{code: string, blocking: bool, message: string, stage_position: int|null, service_code: string|null}
     */
    private static function problem(string $code, bool $blocking, string $message, ?int $stagePosition = null, ?string $serviceCode = null): array
    {
        return [
            'code' => $code,
            'blocking' => $blocking,
            'message' => $message,
            'stage_position' => $stagePosition,
            'service_code' => $serviceCode,
        ];
    }
}
