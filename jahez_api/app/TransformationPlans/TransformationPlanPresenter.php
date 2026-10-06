<?php

namespace App\TransformationPlans;

use App\Enums\DocumentType;
use App\Enums\PlanItemExecutionStatus;
use App\Enums\PlanItemState;
use App\Enums\PlanRequestProgress;
use App\Enums\TransformationPlanStatus;
use App\Models\Factory;
use App\Models\ServiceRequest;
use App\Models\TransformationPlan;
use App\Models\TransformationPlanStage;
use App\Models\TransformationPlanStageItem;
use App\Models\TransformationPlanVersion;
use App\Readiness\ServiceEligibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Renders a plan version for IMC or for the factory (ADR-025): the stages in order, the
 * items in each with their computed state, what they wait for, what runs in parallel, the
 * real status of the linked service request, and progress figures computed from recorded
 * execution statuses only.
 *
 * The factory never receives IMC's internal notes, change notes or actors. Progress is
 * completed ÷ (total − cancelled) items, or null when no item counts.
 */
class TransformationPlanPresenter
{
    public const AUDIENCE_IMC = 'imc';

    public const AUDIENCE_FACTORY = 'factory';

    public function __construct(private readonly ServiceEligibility $eligibility) {}

    /**
     * @return array<string, mixed>
     */
    public function version(TransformationPlan $plan, TransformationPlanVersion $version, string $audience): array
    {
        $forImc = $audience === self::AUDIENCE_IMC;

        $stages = $version->stages()->with([
            'stageItems.item.service.category',
            'stageItems.assignedProvider' => fn (Relation $providers) => $providers->withExists([
                'activeDocuments as has_logo' => fn (Builder $documents) => $documents->where('type', DocumentType::Logo),
            ]),
        ])->get();

        /** @var BaseCollection<int, TransformationPlanStageItem> $placements */
        $placements = $stages->flatMap(fn (TransformationPlanStage $stage) => $stage->stageItems)->keyBy('id');

        $dependsOn = [];
        foreach ($version->dependencies()->get() as $dependency) {
            $dependsOn[$dependency->stage_item_id][] = $dependency->depends_on_stage_item_id;
        }

        $graph = new DependencyGraph(array_keys($placements->all()), $dependsOn);
        $requests = ServiceRequest::query()
            ->whereIn('transformation_plan_item_id', $placements->pluck('transformation_plan_item_id')->all())
            ->with(RequestProgress::RELATIONS)
            ->orderBy('id')
            ->get()
            ->groupBy('transformation_plan_item_id');

        $factory = Factory::query()->findOrFail($plan->factory_id);
        $available = $this->eligibility->availableServiceIds($factory);
        $maySendRequests = $factory->maySendServiceRequests();

        $presentedStages = [];
        $allItems = [];

        foreach ($stages as $stage) {
            $items = [];

            foreach ($stage->stageItems as $placement) {
                $items[] = $this->item($plan, $stage, $placement, $placements, $graph, $requests->get($placement->transformation_plan_item_id, collect()), $available, $maySendRequests, $forImc);
            }

            $allItems = [...$allItems, ...$items];
            $presentedStages[] = [
                'id' => $stage->id,
                'number' => $stage->position,
                'name_ar' => $stage->name_ar,
                'objective_ar' => $stage->objective_ar,
                'description_ar' => $stage->description_ar,
                'factory_instructions_ar' => $stage->factory_instructions_ar,
                ...($forImc ? ['internal_notes' => $stage->internal_notes] : []),
                'planned_start_date' => $stage->planned_start_date?->toDateString(),
                'planned_end_date' => $stage->planned_end_date?->toDateString(),
                'status' => self::stageStatus($items),
                'progress' => self::progress($items),
                'items' => $items,
            ];
        }

        if ($forImc) {
            $version->loadMissing(['createdBy', 'updatedBy', 'publishedBy']);
        }

        return [
            'id' => $version->id,
            'version' => $version->version,
            'status' => $version->status->value,
            'title' => $version->title,
            'summary_ar' => $version->summary_ar,
            ...($forImc ? [
                'revision' => $version->revision,
                'change_note' => $version->change_note,
                'based_on_version_id' => $version->based_on_version_id,
                'created_by' => $version->createdBy === null ? null : ['id' => $version->createdBy->id, 'name' => $version->createdBy->name],
                'updated_by' => $version->updatedBy === null ? null : ['id' => $version->updatedBy->id, 'name' => $version->updatedBy->name],
                'published_by' => $version->publishedBy === null ? null : ['id' => $version->publishedBy->id, 'name' => $version->publishedBy->name],
                'superseded_at' => $version->superseded_at?->toIso8601ZuluString(),
                'updated_at' => $version->updated_at?->toIso8601ZuluString(),
            ] : []),
            'published_at' => $version->published_at?->toIso8601ZuluString(),
            'progress' => self::progress($allItems),
            'stages' => $presentedStages,
        ];
    }

    /**
     * The item's state in one version: its recorded status, refined while not started by
     * its prerequisites there and by the latest live request linked to it.
     */
    public static function state(PlanItemExecutionStatus $status, bool $waitingForPrerequisites, ?PlanRequestProgress $request): PlanItemState
    {
        return match ($status) {
            PlanItemExecutionStatus::InProgress => PlanItemState::InProgress,
            PlanItemExecutionStatus::OnHold => PlanItemState::OnHold,
            PlanItemExecutionStatus::Completed => PlanItemState::Completed,
            PlanItemExecutionStatus::Cancelled => PlanItemState::Cancelled,
            PlanItemExecutionStatus::NotStarted => match (true) {
                $waitingForPrerequisites => PlanItemState::WaitingPrerequisites,
                $request?->allowsStart() === true => PlanItemState::Ready,
                $request === PlanRequestProgress::AwaitingImcReview => PlanItemState::AwaitingApproval,
                default => PlanItemState::NotStarted,
            },
        };
    }

    /**
     * The item actions IMC may take now; TransformationPlanItemController enforces the same
     * rules.
     *
     * @return list<string>
     */
    public static function allowedActions(TransformationPlanStatus $planStatus, PlanItemExecutionStatus $status, PlanItemState $state): array
    {
        if (! in_array($planStatus, [TransformationPlanStatus::Published, TransformationPlanStatus::Suspended], true)) {
            return [];
        }

        return array_values(array_filter([
            $state === PlanItemState::Ready && $planStatus === TransformationPlanStatus::Published ? 'start' : null,
            $status === PlanItemExecutionStatus::InProgress ? 'complete' : null,
            in_array($status, [PlanItemExecutionStatus::NotStarted, PlanItemExecutionStatus::InProgress], true) ? 'hold' : null,
            $status === PlanItemExecutionStatus::OnHold ? 'resume' : null,
            ! $status->isClosed() ? 'cancel' : null,
            $status === PlanItemExecutionStatus::Cancelled ? 'reopen' : null,
        ]));
    }

    /**
     * @param  array<array<string, mixed>>  $items
     * @return array{total: int, completed: int, in_progress: int, on_hold: int, cancelled: int, percent: int|null}
     */
    public static function progress(array $items): array
    {
        $counts = array_count_values(array_map(fn (array $item): string => $item['execution_status'], $items));
        $completed = $counts[PlanItemExecutionStatus::Completed->value] ?? 0;
        $cancelled = $counts[PlanItemExecutionStatus::Cancelled->value] ?? 0;
        $counted = count($items) - $cancelled;

        return [
            'total' => count($items),
            'completed' => $completed,
            'in_progress' => $counts[PlanItemExecutionStatus::InProgress->value] ?? 0,
            'on_hold' => $counts[PlanItemExecutionStatus::OnHold->value] ?? 0,
            'cancelled' => $cancelled,
            'percent' => $counted > 0 ? intdiv($completed * 100, $counted) : null,
        ];
    }

    /**
     * Progress of several versions at once, for lists: one query over the items each
     * version places.
     *
     * @param  list<int>  $versionIds
     * @return array<int, array{total: int, completed: int, in_progress: int, on_hold: int, cancelled: int, percent: int|null}>
     */
    public static function progressOfVersions(array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }

        $rows = TransformationPlanStageItem::query()
            ->join('transformation_plan_items as i', 'i.id', '=', 'transformation_plan_stage_items.transformation_plan_item_id')
            ->whereIn('transformation_plan_stage_items.transformation_plan_version_id', $versionIds)
            ->get(['transformation_plan_stage_items.transformation_plan_version_id as version_id', 'i.execution_status'])
            ->groupBy('version_id');

        $progress = [];

        foreach ($versionIds as $versionId) {
            $progress[$versionId] = self::progress(
                $rows->get($versionId, collect())->map(fn (TransformationPlanStageItem $row): array => ['execution_status' => (string) $row->getAttribute('execution_status')])->values()->all()
            );
        }

        return $progress;
    }

    /**
     * A stage's status from its items' recorded statuses: completed when every item that
     * counts is completed, in progress once any has started, cancelled when every item is.
     *
     * @param  array<array<string, mixed>>  $items
     */
    public static function stageStatus(array $items): string
    {
        $statuses = array_map(fn (array $item): string => $item['execution_status'], $items);
        $counted = array_values(array_filter($statuses, fn (string $status): bool => $status !== PlanItemExecutionStatus::Cancelled->value));

        return match (true) {
            $statuses === [] => 'not_started',
            $counted === [] => 'cancelled',
            array_unique($counted) === [PlanItemExecutionStatus::Completed->value] => 'completed',
            array_intersect($counted, [PlanItemExecutionStatus::InProgress->value, PlanItemExecutionStatus::OnHold->value, PlanItemExecutionStatus::Completed->value]) !== [] => 'in_progress',
            default => 'not_started',
        };
    }

    /**
     * @param  BaseCollection<int, TransformationPlanStageItem>  $placements
     * @param  BaseCollection<int, ServiceRequest>  $requests
     * @param  array<int, int>  $available
     * @return array<string, mixed>
     */
    private function item(TransformationPlan $plan, TransformationPlanStage $stage, TransformationPlanStageItem $placement, BaseCollection $placements, DependencyGraph $graph, BaseCollection $requests, array $available, bool $maySendRequests, bool $forImc): array
    {
        $byId = $placements->all();
        $item = $placement->item;
        $service = $item->service;
        $prerequisites = array_map(fn (int|string $id): TransformationPlanStageItem => $byId[(int) $id], $graph->prerequisitesOf($placement->id));
        $waitingFor = array_values(array_filter($prerequisites, fn (TransformationPlanStageItem $prerequisite): bool => $prerequisite->item->execution_status !== PlanItemExecutionStatus::Completed));

        $live = RequestProgress::latestLive($requests);
        $shown = $live ?? $requests->last();
        $progress = $live === null ? null : RequestProgress::of($live);
        $state = self::state($item->execution_status, $waitingFor !== [], $progress);
        $serviceAvailable = in_array($service->id, $available, true);
        $sameStage = array_map(fn (TransformationPlanStageItem $other): int => $other->id, $stage->stageItems->all());
        $provider = $placement->assignedProvider;
        $statusOf = fn (TransformationPlanStageItem $other): array => [
            'item_id' => $other->item->id,
            'service_name_ar' => $other->item->service->name_ar,
            'execution_status' => $other->item->execution_status->value,
        ];

        return [
            'id' => $placement->id,
            'item_id' => $item->id,
            'position' => $placement->position,
            'service' => [
                'id' => $service->id,
                'code' => $service->code,
                'name_ar' => $service->name_ar,
                'category' => $service->category === null ? null : ['code' => $service->category->code, 'name_ar' => $service->category->name_ar],
            ],
            'provider' => $provider === null ? null : [
                'id' => $provider->id,
                'name' => $provider->name,
                'has_logo' => (bool) ($provider->getAttribute('has_logo') ?? false),
            ],
            'instructions_ar' => $placement->instructions_ar,
            ...($forImc ? ['internal_notes' => $placement->internal_notes] : []),
            'planned_start_date' => $placement->planned_start_date?->toDateString(),
            'planned_end_date' => $placement->planned_end_date?->toDateString(),
            'execution_status' => $item->execution_status->value,
            'state' => $state->value,
            'started_at' => $item->started_at?->toIso8601ZuluString(),
            'completed_at' => $item->completed_at?->toIso8601ZuluString(),
            'status_changed_at' => $item->status_changed_at?->toIso8601ZuluString(),
            ...($forImc ? ['status_reason' => $item->status_reason] : []),
            'depends_on' => array_map($statusOf, $prerequisites),
            'waiting_for' => array_map($statusOf, $waitingFor),
            'dependents' => array_map(fn (int|string $id): int => $byId[(int) $id]->item->id, $graph->dependentsOf($placement->id)),
            'parallel_with' => array_map(fn (int|string $id): int => $byId[(int) $id]->item->id, $graph->parallelWith($placement->id, $sameStage)),
            'service_available' => $serviceAvailable,
            'request' => $shown === null ? null : RequestProgress::summary($shown),
            'requests_count' => $requests->count(),
            // A hint for the factory's request button; POST /service-requests checks everything again.
            'can_request' => $plan->status === TransformationPlanStatus::Published
                && $serviceAvailable
                && $maySendRequests
                && $live === null
                && ! $item->execution_status->isClosed(),
            ...($forImc ? [
                'allowed_actions' => self::allowedActions($plan->status, $item->execution_status, $state),
                'attention' => array_values(array_filter([
                    $serviceAvailable ? null : 'service_unavailable',
                    $progress === PlanRequestProgress::NoActiveProvider ? 'no_active_provider' : null,
                    $live === null && $shown !== null && RequestProgress::of($shown) === PlanRequestProgress::ImcRejected ? 'agreement_rejected' : null,
                    collect($prerequisites)->contains(fn (TransformationPlanStageItem $prerequisite): bool => $prerequisite->item->execution_status === PlanItemExecutionStatus::Cancelled) ? 'prerequisite_cancelled' : null,
                ])),
            ] : []),
        ];
    }
}
