<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\NotificationEvent;
use App\Enums\PlanItemExecutionStatus;
use App\Enums\PlanItemState;
use App\Enums\TransformationPlanStatus;
use App\Enums\TransformationPlanVersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TransformationPlanActionRequest;
use App\Http\Resources\V1\TransformationPlanResource;
use App\Models\AuditLog;
use App\Models\TransformationPlan;
use App\Models\TransformationPlanItem;
use App\Models\TransformationPlanStageItem;
use App\Models\User;
use App\Notifications\PlatformNotifier;
use App\Readiness\LevelProgression;
use App\TransformationPlans\RequestProgress;
use App\TransformationPlans\TransformationPlanPresenter;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The execution of a plan item, recorded by IMC (ADR-025; owner decision 2026-10-05,
 * OQ-52 for who should report it and on what evidence). The status is separate from the
 * linked service request: an item starts only when the plan is published, every item it
 * depends on in the published version is completed, and its request's agreement is
 * approved by IMC (or approval is not required). Each change locks the plan, then the
 * item, is audited and notifies the factory's members. Completing or cancelling an item
 * may complete the factory's level and open the next one (ADR-026).
 */
class TransformationPlanItemController extends Controller
{
    public function __construct(private readonly LevelProgression $progression) {}

    public function start(TransformationPlanActionRequest $request, TransformationPlan $transformationPlan, TransformationPlanItem $transformationPlanItem, #[CurrentUser] User $user): TransformationPlanResource
    {
        return $this->act($transformationPlan, $transformationPlanItem, $user, $request->reason(), function (TransformationPlan $plan, TransformationPlanItem $item, TransformationPlanStageItem $placement): PlanItemExecutionStatus {
            if ($plan->status !== TransformationPlanStatus::Published) {
                throw new ConflictHttpException("The plan is {$plan->status->value}: no item may start.");
            }

            $state = $this->stateOf($item, $placement);

            if ($state !== PlanItemState::Ready) {
                throw new ConflictHttpException(match ($state) {
                    PlanItemState::WaitingPrerequisites => 'This item waits for the items it depends on to be completed.',
                    PlanItemState::AwaitingApproval => 'The agreement for this item is waiting for IMC review.',
                    PlanItemState::NotStarted => 'This item has no service request with an approved agreement yet.',
                    default => "This item is {$item->execution_status->value} and cannot start.",
                });
            }

            return PlanItemExecutionStatus::InProgress;
        });
    }

    public function complete(TransformationPlanActionRequest $request, TransformationPlan $transformationPlan, TransformationPlanItem $transformationPlanItem, #[CurrentUser] User $user): TransformationPlanResource
    {
        return $this->act($transformationPlan, $transformationPlanItem, $user, $request->reason(), fn (): PlanItemExecutionStatus => PlanItemExecutionStatus::Completed);
    }

    public function hold(TransformationPlanActionRequest $request, TransformationPlan $transformationPlan, TransformationPlanItem $transformationPlanItem, #[CurrentUser] User $user): TransformationPlanResource
    {
        return $this->act($transformationPlan, $transformationPlanItem, $user, $request->reason(), fn (): PlanItemExecutionStatus => PlanItemExecutionStatus::OnHold);
    }

    /**
     * Resume an item on hold: back in progress if it had started, otherwise not started.
     */
    public function resume(TransformationPlanActionRequest $request, TransformationPlan $transformationPlan, TransformationPlanItem $transformationPlanItem, #[CurrentUser] User $user): TransformationPlanResource
    {
        return $this->act($transformationPlan, $transformationPlanItem, $user, $request->reason(), function (TransformationPlan $plan, TransformationPlanItem $item): PlanItemExecutionStatus {
            if ($item->execution_status !== PlanItemExecutionStatus::OnHold) {
                throw new ConflictHttpException("This item is {$item->execution_status->value}; only an item on hold resumes.");
            }

            return $item->started_at !== null ? PlanItemExecutionStatus::InProgress : PlanItemExecutionStatus::NotStarted;
        });
    }

    public function cancel(TransformationPlanActionRequest $request, TransformationPlan $transformationPlan, TransformationPlanItem $transformationPlanItem, #[CurrentUser] User $user): TransformationPlanResource
    {
        return $this->act($transformationPlan, $transformationPlanItem, $user, $request->reason(), fn (): PlanItemExecutionStatus => PlanItemExecutionStatus::Cancelled);
    }

    public function reopen(TransformationPlanActionRequest $request, TransformationPlan $transformationPlan, TransformationPlanItem $transformationPlanItem, #[CurrentUser] User $user): TransformationPlanResource
    {
        return $this->act($transformationPlan, $transformationPlanItem, $user, $request->reason(), function (TransformationPlan $plan, TransformationPlanItem $item): PlanItemExecutionStatus {
            if ($item->execution_status !== PlanItemExecutionStatus::Cancelled) {
                throw new ConflictHttpException("This item is {$item->execution_status->value}; only a cancelled item reopens.");
            }

            return PlanItemExecutionStatus::NotStarted;
        });
    }

    /**
     * @param  callable(TransformationPlan, TransformationPlanItem, TransformationPlanStageItem): PlanItemExecutionStatus  $decide
     */
    private function act(TransformationPlan $plan, TransformationPlanItem $item, User $user, ?string $reason, callable $decide): TransformationPlanResource
    {
        if ($item->transformation_plan_id !== $plan->id) {
            abort(404);
        }

        DB::transaction(function () use ($plan, $item, $user, $reason, $decide): void {
            $lockedPlan = TransformationPlan::query()->lockForUpdate()->findOrFail($plan->id);
            $lockedItem = TransformationPlanItem::query()->lockForUpdate()->findOrFail($item->id);

            if (! in_array($lockedPlan->status, [TransformationPlanStatus::Published, TransformationPlanStatus::Suspended], true)) {
                throw new ConflictHttpException("The plan is {$lockedPlan->status->value}: its items take no action.");
            }

            $placement = TransformationPlanStageItem::query()
                ->where('transformation_plan_item_id', $lockedItem->id)
                ->whereHas('version', fn (Builder $versions) => $versions->where('transformation_plan_id', $lockedPlan->id)->where('status', TransformationPlanVersionStatus::Published))
                ->first();

            if ($placement === null) {
                throw new ConflictHttpException('This item is not in the published version of the plan.');
            }

            $from = $lockedItem->execution_status;
            $next = $decide($lockedPlan, $lockedItem, $placement);
            $lockedItem->moveTo($next, $user, $reason);

            AuditLog::record(AuditEvent::TransformationPlanItemStatusChanged, $user, $lockedPlan, [
                'item_id' => $lockedItem->id,
                'service' => $lockedItem->service()->value('code'),
                'from' => $from->value,
                'to' => $next->value,
                'reason' => $reason,
            ]);

            PlatformNotifier::factoryMembers(
                $lockedPlan->factory_id,
                NotificationEvent::TransformationPlanItemUpdated,
                "transformation_plan_item.{$lockedItem->id}.{$next->value}.".now()->getTimestampMs(),
                'حدّث مركز تحديث الصناعة حالة إحدى خدمات خطة التحول الرقمي لمنشأتكم.',
                '/factory/roadmap',
                ['type' => 'transformation_plan', 'id' => $lockedPlan->id],
            );

            if ($next->isClosed()) {
                $this->progression->advance($lockedPlan, $user);
            }
        });

        return TransformationPlanController::detailed($plan);
    }

    /**
     * The item's state in the published version: its prerequisites there and the latest
     * live request linked to it.
     */
    private function stateOf(TransformationPlanItem $item, TransformationPlanStageItem $placement): PlanItemState
    {
        $waiting = $placement->prerequisites()->with('item')->get()
            ->contains(fn (TransformationPlanStageItem $prerequisite): bool => $prerequisite->item->execution_status !== PlanItemExecutionStatus::Completed);

        $live = RequestProgress::latestLive($item->serviceRequests()->with(RequestProgress::RELATIONS)->get());

        return TransformationPlanPresenter::state($item->execution_status, $waiting, $live === null ? null : RequestProgress::of($live));
    }
}
