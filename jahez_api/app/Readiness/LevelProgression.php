<?php

namespace App\Readiness;

use App\Enums\AuditEvent;
use App\Enums\NotificationEvent;
use App\Enums\PlanItemExecutionStatus;
use App\Enums\ReadinessCategoryCode;
use App\Models\AuditLog;
use App\Models\Factory;
use App\Models\ReadinessCategory;
use App\Models\ReadinessLevelService;
use App\Models\ReadinessLevelUnlock;
use App\Models\TransformationPlan;
use App\Models\TransformationPlanItem;
use App\Models\TransformationPlanStageItem;
use App\Models\User;
use App\Notifications\PlatformNotifier;

/**
 * Opens the next readiness level of a factory that completed its plan's services of its
 * current level (ADR-026, owner decisions 2026-10-06):
 * - a service belongs to the lowest level IMC made it available to (an active
 *   readiness_level_services row); a service available to no level belongs to none;
 * - a level is complete when the published version of the factory's plan holds at least
 *   one item of that level that is not cancelled, and every such item was recorded as
 *   completed by IMC (OQ-52); a level with no such item opens nothing;
 * - the opened level is recorded once and never closed again (readiness_level_unlocks),
 *   audited and notified to the factory's members, and the check repeats from it.
 *
 * Called inside the transaction of an IMC action that may complete a level (closing a
 * plan item, publishing a version), after the plan row was locked for update: item
 * statuses change only under that lock, so the check sees a consistent plan.
 */
class LevelProgression
{
    public function __construct(private readonly ServiceEligibility $eligibility) {}

    /**
     * @return list<ReadinessLevelUnlock> the levels opened, lowest first
     */
    public function advance(TransformationPlan $lockedPlan, User $actor): array
    {
        $factory = Factory::query()->findOrFail($lockedPlan->factory_id);
        $level = $this->eligibility->levelOf($factory);
        $published = $lockedPlan->publishedVersion()->first();

        if ($level === null || $published === null) {
            return [];
        }

        $items = TransformationPlanItem::query()
            ->whereIn('id', TransformationPlanStageItem::query()->where('transformation_plan_version_id', $published->id)->select('transformation_plan_item_id'))
            ->get();
        $serviceLevels = $this->serviceLevels($items->pluck('catalog_service_id')->all());
        $unlocks = [];

        while (($next = $level->next()) !== null) {
            $itemsAtLevel = $items->filter(fn (TransformationPlanItem $item): bool => ($serviceLevels[$item->catalog_service_id] ?? null) === $level
                && $item->execution_status !== PlanItemExecutionStatus::Cancelled);

            if ($itemsAtLevel->isEmpty() || $itemsAtLevel->contains(fn (TransformationPlanItem $item): bool => $item->execution_status !== PlanItemExecutionStatus::Completed)) {
                break;
            }

            $unlock = new ReadinessLevelUnlock;
            $unlock->factory_id = $factory->id;
            $unlock->level = $next;
            $unlock->from_level = $level;
            $unlock->transformation_plan_id = $lockedPlan->id;
            $unlock->unlocked_by_user_id = $actor->id;
            $unlock->unlocked_at = now();
            $unlock->save();

            AuditLog::record(AuditEvent::ReadinessLevelUnlocked, $actor, $factory, [
                'from' => $level->value,
                'to' => $next->value,
                'transformation_plan_id' => $lockedPlan->id,
                'completed_items' => $itemsAtLevel->count(),
            ]);

            $names = $this->namesFor($factory);

            PlatformNotifier::factoryMembers(
                $factory->id,
                NotificationEvent::ReadinessLevelUnlocked,
                "readiness_level.{$factory->id}.{$next->value}.unlocked",
                'أتمّت منشأتكم خدمات مستوى «'.($names[$level->value] ?? $level->value).'» في خطة التحول الرقمي، فأصبح مستوى «'.($names[$next->value] ?? $next->value).'» متاحًا لكم بخدماته ومزوّديه.',
                '/factory/services',
                ['type' => 'factory', 'id' => $factory->id],
            );

            $unlocks[] = $unlock;
            $level = $next;
        }

        return $unlocks;
    }

    /**
     * The lowest level each service is available to.
     *
     * @param  array<int, int>  $serviceIds
     * @return array<int, ReadinessCategoryCode>
     */
    private function serviceLevels(array $serviceIds): array
    {
        $levels = [];

        ReadinessLevelService::query()
            ->whereIn('catalog_service_id', $serviceIds)
            ->where('is_active', true)
            ->get()
            ->each(function (ReadinessLevelService $row) use (&$levels): void {
                $lowest = $levels[$row->catalog_service_id] ?? null;

                if ($lowest === null || $row->level->rank() < $lowest->rank()) {
                    $levels[$row->catalog_service_id] = $row->level;
                }
            });

        return $levels;
    }

    /**
     * The names of the levels in the factory's assessed questionnaire version, by code.
     *
     * @return array<string, string>
     */
    private function namesFor(Factory $factory): array
    {
        $assessment = $this->eligibility->currentAssessment($factory);

        return $assessment === null ? [] : ReadinessCategory::query()
            ->where('readiness_questionnaire_id', $assessment->readiness_questionnaire_id)
            ->get()
            ->mapWithKeys(fn (ReadinessCategory $category): array => [$category->code->value => $category->name_ar])
            ->all();
    }
}
