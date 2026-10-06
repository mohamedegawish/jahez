<?php

namespace App\TransformationPlans;

use App\Enums\AuditEvent;
use App\Enums\NotificationEvent;
use App\Enums\TransformationPlanStatus;
use App\Enums\TransformationPlanVersionStatus;
use App\Models\AuditLog;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\TransformationPlan;
use App\Models\TransformationPlanDependency;
use App\Models\TransformationPlanItem;
use App\Models\TransformationPlanStage;
use App\Models\TransformationPlanStageItem;
use App\Models\TransformationPlanVersion;
use App\Models\User;
use App\Notifications\PlatformNotifier;
use App\Readiness\LevelProgression;
use App\Readiness\ServiceEligibility;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Writing a factory's transformation plan (ADR-025): creating it, drafting a version,
 * saving the draft's structure, discarding it, deleting a plan that was never published,
 * and publishing.
 *
 * Every change runs in one transaction that locks the plan row first, writes the audit
 * entry and, on publication, notifies the factory's members. A draft save names the
 * revision it was based on: a stale save gets 409, so two administrators never overwrite
 * each other silently. Published versions are never changed; publishing a draft
 * supersedes the previous version, which stays readable.
 *
 * @phpstan-type ItemDefinition array{service: string, service_provider_id?: int|null, instructions_ar?: string|null, internal_notes?: string|null, planned_start_date?: string|null, planned_end_date?: string|null, depends_on?: list<string>}
 * @phpstan-type StageDefinition array{name_ar: string, objective_ar?: string|null, description_ar?: string|null, factory_instructions_ar?: string|null, internal_notes?: string|null, planned_start_date?: string|null, planned_end_date?: string|null, items: list<ItemDefinition>}
 * @phpstan-type DraftDefinition array{title: string, summary_ar?: string|null, change_note?: string|null, stages: list<StageDefinition>}
 */
class TransformationPlanDraft
{
    public function __construct(
        private readonly ServiceEligibility $eligibility,
        private readonly TransformationPlanReview $review,
        private readonly LevelProgression $progression,
    ) {}

    /**
     * Create the factory's plan with an empty first draft. The factory needs a readiness
     * assessment (its services follow its level) and no other open plan.
     */
    public function create(Factory $factory, User $actor, string $title, ?string $summary): TransformationPlan
    {
        try {
            return DB::transaction(function () use ($factory, $actor, $title, $summary): TransformationPlan {
                $locked = Factory::query()->lockForUpdate()->findOrFail($factory->id);
                $assessment = $this->eligibility->currentAssessment($locked);

                if ($assessment === null) {
                    throw new ConflictHttpException('The factory has not completed the digital readiness assessment, so a plan cannot be built for its level yet.');
                }

                if (TransformationPlan::query()->where('factory_id', $locked->id)->where('is_open', true)->exists()) {
                    throw new ConflictHttpException('The factory already has an open transformation plan; revise it or close it first.');
                }

                $plan = new TransformationPlan;
                $plan->factory_id = $locked->id;
                $plan->based_on_readiness_assessment_id = $assessment->id;
                $plan->created_by_user_id = $actor->id;
                $plan->save();

                $version = new TransformationPlanVersion;
                $version->transformation_plan_id = $plan->id;
                $version->version = 1;
                $version->title = $title;
                $version->summary_ar = $summary;
                $version->created_by_user_id = $actor->id;
                $version->updated_by_user_id = $actor->id;
                $version->save();

                AuditLog::record(AuditEvent::TransformationPlanCreated, $actor, $plan, [
                    'factory_id' => $locked->id,
                    'readiness_assessment_id' => $assessment->id,
                    'level' => $assessment->category?->code->value,
                ]);

                return $plan;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException('The factory already has an open transformation plan; revise it or close it first.');
        }
    }

    /**
     * Start a new draft from the published version: its stages, placements and
     * dependencies are copied, and the items (with their execution state) are shared.
     */
    public function startDraft(TransformationPlan $plan, User $actor): TransformationPlanVersion
    {
        return DB::transaction(function () use ($plan, $actor): TransformationPlanVersion {
            $locked = $this->lockPlan($plan);

            if ($locked->status === TransformationPlanStatus::Closed) {
                throw new ConflictHttpException('This transformation plan is closed and cannot be revised; create a new plan for the factory.');
            }

            if ($locked->draftVersion()->exists()) {
                throw new ConflictHttpException('This plan already has a draft; edit it or discard it first.');
            }

            $published = $locked->publishedVersion()->firstOrFail();

            $draft = new TransformationPlanVersion;
            $draft->transformation_plan_id = $locked->id;
            $draft->version = (int) $locked->versions()->max('version') + 1;
            $draft->title = $published->title;
            $draft->summary_ar = $published->summary_ar;
            $draft->based_on_version_id = $published->id;
            $draft->created_by_user_id = $actor->id;
            $draft->updated_by_user_id = $actor->id;
            $draft->save();

            $this->copyStructure($published, $draft);

            AuditLog::record(AuditEvent::TransformationPlanDraftStarted, $actor, $locked, [
                'version' => $draft->version,
                'based_on_version' => $published->version,
            ]);

            return $draft;
        });
    }

    /**
     * Replace the draft's structure with the definition: stages and their items in the
     * order given, dependencies by service code. Refused with 409 when the draft changed
     * since `$basedOnRevision`, and with 422 for a structure that cannot be stored.
     *
     * @param  DraftDefinition  $definition
     */
    public function save(TransformationPlan $plan, array $definition, int $basedOnRevision, User $actor): TransformationPlanVersion
    {
        return DB::transaction(function () use ($plan, $definition, $basedOnRevision, $actor): TransformationPlanVersion {
            $locked = $this->lockPlan($plan);
            $draft = $locked->draftVersion()->lockForUpdate()->first();

            if ($draft === null) {
                throw new ConflictHttpException('This plan has no draft; start one first.');
            }

            if ($draft->revision !== $basedOnRevision) {
                throw new ConflictHttpException("The draft was changed by someone else (revision {$draft->revision}); reload it and apply your changes again.");
            }

            $services = $this->checkStructure($definition);

            $this->clearStructure($draft);
            $counts = $this->writeStructure($locked, $draft, $definition, $services);
            $this->pruneUnusedItems($locked);

            $draft->title = $definition['title'];
            $draft->summary_ar = $definition['summary_ar'] ?? null;
            $draft->change_note = $definition['change_note'] ?? null;
            $draft->revision = $draft->revision + 1;
            $draft->updated_by_user_id = $actor->id;
            $draft->save();

            AuditLog::record(AuditEvent::TransformationPlanDraftSaved, $actor, $locked, [
                'version' => $draft->version,
                'revision' => $draft->revision,
                ...$counts,
            ]);

            return $draft;
        });
    }

    /**
     * Discard the draft of a plan that has a published version.
     */
    public function discard(TransformationPlan $plan, User $actor): void
    {
        DB::transaction(function () use ($plan, $actor): void {
            $locked = $this->lockPlan($plan);
            $draft = $locked->draftVersion()->lockForUpdate()->first();

            if ($draft === null) {
                throw new ConflictHttpException('This plan has no draft to discard.');
            }

            if ($locked->status === TransformationPlanStatus::Draft) {
                throw new ConflictHttpException('This plan was never published: delete the plan instead of its only draft.');
            }

            $this->clearStructure($draft);
            $draft->delete();
            $this->pruneUnusedItems($locked);

            AuditLog::record(AuditEvent::TransformationPlanDraftDiscarded, $actor, $locked, ['version' => $draft->version]);
        });
    }

    /**
     * Delete a plan that was never published, with its draft and items. A plan the
     * factory has seen is closed instead, never deleted.
     */
    public function delete(TransformationPlan $plan, User $actor): void
    {
        DB::transaction(function () use ($plan, $actor): void {
            $locked = $this->lockPlan($plan);

            if ($locked->status !== TransformationPlanStatus::Draft) {
                throw new ConflictHttpException("This plan is {$locked->status->value}: a plan the factory has seen is closed, never deleted.");
            }

            foreach ($locked->versions()->get() as $version) {
                $this->clearStructure($version);
                $version->delete();
            }

            TransformationPlanItem::query()->where('transformation_plan_id', $locked->id)->delete();

            AuditLog::record(AuditEvent::TransformationPlanDeleted, $actor, $locked, ['factory_id' => $locked->factory_id]);

            $locked->delete();
        });
    }

    /**
     * Publish the draft: it becomes the version the factory sees, the previous one is
     * superseded, and the plan's readiness basis becomes the factory's current
     * assessment. Refused (422) while a blocking problem remains; from version 2 a change
     * note is required.
     */
    public function publish(TransformationPlan $plan, User $actor, ?string $changeNote): TransformationPlanVersion
    {
        return DB::transaction(function () use ($plan, $actor, $changeNote): TransformationPlanVersion {
            $locked = $this->lockPlan($plan);

            if ($locked->status === TransformationPlanStatus::Closed) {
                throw new ConflictHttpException('This transformation plan is closed.');
            }

            $draft = $locked->draftVersion()->lockForUpdate()->first();

            if ($draft === null) {
                throw new ConflictHttpException('This plan has no draft to publish.');
            }

            $note = $changeNote ?? $draft->change_note;

            if ($draft->version > 1 && ($note === null || trim($note) === '')) {
                throw ValidationException::withMessages(['change_note' => 'Say what changed in this version and why.']);
            }

            $problems = $this->review->problems($locked, $draft);

            if (TransformationPlanReview::blocks($problems)) {
                throw ValidationException::withMessages([
                    'draft' => collect($problems)->where('blocking', true)->pluck('message')->values()->all(),
                ]);
            }

            $previous = $locked->publishedVersion()->lockForUpdate()->first();

            if ($previous !== null) {
                $previous->status = TransformationPlanVersionStatus::Superseded;
                $previous->is_published = null;
                $previous->superseded_at = now();
                $previous->save();
            }

            $draft->status = TransformationPlanVersionStatus::Published;
            $draft->is_draft = null;
            $draft->is_published = true;
            $draft->change_note = $note;
            $draft->published_at = now();
            $draft->published_by_user_id = $actor->id;
            $draft->save();

            if ($locked->status === TransformationPlanStatus::Draft) {
                $locked->moveTo(TransformationPlanStatus::Published);
            }

            $locked->based_on_readiness_assessment_id = $this->eligibility->currentAssessment(Factory::query()->findOrFail($locked->factory_id))?->id;
            $locked->save();

            AuditLog::record(AuditEvent::TransformationPlanPublished, $actor, $locked, [
                'version' => $draft->version,
                'previous_version' => $previous?->version,
                'stages' => TransformationPlanStage::query()->where('transformation_plan_version_id', $draft->id)->count(),
                'items' => TransformationPlanStageItem::query()->where('transformation_plan_version_id', $draft->id)->count(),
                'warnings' => collect($problems)->pluck('code')->values()->all(),
            ]);

            PlatformNotifier::factoryMembers(
                $locked->factory_id,
                NotificationEvent::TransformationPlanPublished,
                "transformation_plan.{$locked->id}.version.{$draft->version}.published",
                $previous === null
                    ? 'نشر مركز تحديث الصناعة خطة التحول الرقمي لمنشأتكم. اطّلعوا على مراحلها وخدماتها.'
                    : 'نشر مركز تحديث الصناعة إصدارًا محدّثًا من خطة التحول الرقمي لمنشأتكم.',
                '/factory/roadmap',
                ['type' => 'transformation_plan', 'id' => $locked->id],
            );

            // A version that drops the last open item of the factory's level completes it (ADR-026).
            $this->progression->advance($locked, $actor);

            return $draft;
        });
    }

    /**
     * Check what the request validation cannot: unknown services, a service placed twice,
     * dependencies on a service the draft does not place, on itself, or in a cycle.
     * Returns the services by code.
     *
     * @param  DraftDefinition  $definition
     * @return array<string, CatalogService>
     */
    private function checkStructure(array $definition): array
    {
        $codes = [];
        $errors = [];

        foreach ($definition['stages'] as $s => $stage) {
            foreach ($stage['items'] as $i => $item) {
                if (in_array($item['service'], $codes, true)) {
                    $errors["stages.{$s}.items.{$i}.service"] = 'A service appears once per plan; it is already in another place.';
                }

                $codes[] = $item['service'];
            }
        }

        $services = CatalogService::query()->whereIn('code', $codes)->get()->keyBy('code');
        $edges = [];

        foreach ($definition['stages'] as $s => $stage) {
            foreach ($stage['items'] as $i => $item) {
                if (! $services->has($item['service'])) {
                    $errors["stages.{$s}.items.{$i}.service"] = 'The service is not in the catalog.';
                }

                foreach ($item['depends_on'] ?? [] as $d => $prerequisite) {
                    if ($prerequisite === $item['service']) {
                        $errors["stages.{$s}.items.{$i}.depends_on.{$d}"] = 'A service cannot depend on itself.';
                    } elseif (! in_array($prerequisite, $codes, true)) {
                        $errors["stages.{$s}.items.{$i}.depends_on.{$d}"] = 'The service it depends on is not in this plan.';
                    } else {
                        $edges[$item['service']][] = $prerequisite;
                    }
                }
            }
        }

        if ($errors === []) {
            $cycle = (new DependencyGraph(array_values(array_unique($codes)), $edges))->findCycle();

            if ($cycle !== null) {
                $errors['stages'] = 'The dependencies form a cycle: '.implode(' → ', $cycle).'.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** @var array<string, CatalogService> */
        return $services->all();
    }

    /**
     * @param  DraftDefinition  $definition
     * @param  array<string, CatalogService>  $services
     * @return array{stages: int, items: int, dependencies: int}
     */
    private function writeStructure(TransformationPlan $plan, TransformationPlanVersion $draft, array $definition, array $services): array
    {
        $placements = [];
        $dependencies = 0;

        foreach ($definition['stages'] as $s => $stageDefinition) {
            $stage = new TransformationPlanStage;
            $stage->transformation_plan_version_id = $draft->id;
            $stage->position = $s + 1;
            $stage->name_ar = $stageDefinition['name_ar'];
            $stage->objective_ar = $stageDefinition['objective_ar'] ?? null;
            $stage->description_ar = $stageDefinition['description_ar'] ?? null;
            $stage->factory_instructions_ar = $stageDefinition['factory_instructions_ar'] ?? null;
            $stage->internal_notes = $stageDefinition['internal_notes'] ?? null;
            $stage->planned_start_date = self::date($stageDefinition['planned_start_date'] ?? null);
            $stage->planned_end_date = self::date($stageDefinition['planned_end_date'] ?? null);
            $stage->save();

            foreach ($stageDefinition['items'] as $i => $itemDefinition) {
                $item = TransformationPlanItem::query()
                    ->where('transformation_plan_id', $plan->id)
                    ->where('catalog_service_id', $services[$itemDefinition['service']]->id)
                    ->first();

                if ($item === null) {
                    $item = new TransformationPlanItem;
                    $item->transformation_plan_id = $plan->id;
                    $item->catalog_service_id = $services[$itemDefinition['service']]->id;
                    $item->save();
                }

                $placement = new TransformationPlanStageItem;
                $placement->transformation_plan_version_id = $draft->id;
                $placement->transformation_plan_stage_id = $stage->id;
                $placement->transformation_plan_item_id = $item->id;
                $placement->position = $i + 1;
                $placement->service_provider_id = $itemDefinition['service_provider_id'] ?? null;
                $placement->instructions_ar = $itemDefinition['instructions_ar'] ?? null;
                $placement->internal_notes = $itemDefinition['internal_notes'] ?? null;
                $placement->planned_start_date = self::date($itemDefinition['planned_start_date'] ?? null);
                $placement->planned_end_date = self::date($itemDefinition['planned_end_date'] ?? null);
                $placement->save();

                $placements[$itemDefinition['service']] = $placement;
            }
        }

        foreach ($definition['stages'] as $stageDefinition) {
            foreach ($stageDefinition['items'] as $itemDefinition) {
                foreach (array_unique($itemDefinition['depends_on'] ?? []) as $prerequisite) {
                    $dependency = new TransformationPlanDependency;
                    $dependency->transformation_plan_version_id = $draft->id;
                    $dependency->stage_item_id = $placements[$itemDefinition['service']]->id;
                    $dependency->depends_on_stage_item_id = $placements[$prerequisite]->id;
                    $dependency->save();
                    $dependencies++;
                }
            }
        }

        return ['stages' => count($definition['stages']), 'items' => count($placements), 'dependencies' => $dependencies];
    }

    /**
     * Copy a version's stages, placements and dependencies into another version.
     */
    private function copyStructure(TransformationPlanVersion $from, TransformationPlanVersion $to): void
    {
        $placementIds = [];

        foreach ($from->stages()->with('stageItems')->get() as $stage) {
            $copy = $stage->replicate(['transformation_plan_version_id']);
            $copy->transformation_plan_version_id = $to->id;
            $copy->save();

            foreach ($stage->stageItems as $placement) {
                $placementCopy = $placement->replicate(['transformation_plan_version_id', 'transformation_plan_stage_id']);
                $placementCopy->transformation_plan_version_id = $to->id;
                $placementCopy->transformation_plan_stage_id = $copy->id;
                $placementCopy->save();
                $placementIds[$placement->id] = $placementCopy->id;
            }
        }

        foreach ($from->dependencies()->get() as $dependency) {
            $copy = new TransformationPlanDependency;
            $copy->transformation_plan_version_id = $to->id;
            $copy->stage_item_id = $placementIds[$dependency->stage_item_id];
            $copy->depends_on_stage_item_id = $placementIds[$dependency->depends_on_stage_item_id];
            $copy->save();
        }
    }

    /**
     * Delete a version's dependencies, placements and stages, children first (the
     * foreign keys do not cascade).
     */
    private function clearStructure(TransformationPlanVersion $version): void
    {
        TransformationPlanDependency::query()->where('transformation_plan_version_id', $version->id)->delete();
        TransformationPlanStageItem::query()->where('transformation_plan_version_id', $version->id)->delete();
        TransformationPlanStage::query()->where('transformation_plan_version_id', $version->id)->delete();
    }

    /**
     * Remove the plan's items that no version places, that never received a request and
     * never started: they were only ever part of an unsaved idea.
     */
    private function pruneUnusedItems(TransformationPlan $plan): void
    {
        TransformationPlanItem::query()
            ->where('transformation_plan_id', $plan->id)
            ->where('execution_status', 'not_started')
            ->whereNull('status_changed_at')
            ->whereDoesntHave('placements')
            ->whereDoesntHave('serviceRequests')
            ->delete();
    }

    private static function date(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::createFromFormat('Y-m-d', $value)?->startOfDay();
    }

    private function lockPlan(TransformationPlan $plan): TransformationPlan
    {
        return TransformationPlan::query()->lockForUpdate()->findOrFail($plan->id);
    }
}
