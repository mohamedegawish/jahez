<?php

namespace App\Http\Resources\V1;

use App\Enums\Permission;
use App\Models\ReadinessAssessment;
use App\Models\TransformationPlan;
use App\Models\User;
use App\Readiness\ServiceEligibility;
use App\TransformationPlans\TransformationPlanPresenter;
use App\TransformationPlans\TransformationPlanReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A factory's transformation plan (ADR-025). The summary names the factory, the status,
 * the published version and its progress. The detailed form adds the published version as
 * presented to the reader; IMC also gets the draft with its publication review, the
 * readiness basis and whether the factory's level changed since. A factory member never
 * receives a draft, IMC's notes, the status reason or the version actors.
 *
 * Controllers load `industrialFactory`, `publishedVersion`, `draftVersion` and
 * `basedOnAssessment.category`.
 *
 * @mixin TransformationPlan
 */
class TransformationPlanResource extends JsonResource
{
    private bool $detailed = false;

    /**
     * @var array{total: int, completed: int, in_progress: int, on_hold: int, cancelled: int, percent: int|null}|null
     */
    private ?array $progress = null;

    public function detailed(): self
    {
        $this->detailed = true;

        return $this;
    }

    /**
     * The published version's progress, computed by the caller for a whole page.
     *
     * @param  array{total: int, completed: int, in_progress: int, on_hold: int, cancelled: int, percent: int|null}|null  $progress
     */
    public function withProgress(?array $progress): self
    {
        $this->progress = $progress;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $forImc = $user instanceof User && $user->hasPermission(Permission::TransformationPlansViewAny);
        $audience = $forImc ? TransformationPlanPresenter::AUDIENCE_IMC : TransformationPlanPresenter::AUDIENCE_FACTORY;
        $presenter = app(TransformationPlanPresenter::class);
        $published = $this->publishedVersion;
        $draft = $forImc ? $this->draftVersion : null;
        $presentedPublished = $this->detailed && $published !== null ? $presenter->version($this->resource, $published, $audience) : null;

        return [
            'id' => $this->id,
            'factory' => $this->whenLoaded('industrialFactory', fn (): array => [
                'id' => $this->industrialFactory->id,
                'name' => $this->industrialFactory->name,
            ]),
            'status' => $this->status->value,
            ...($forImc ? ['status_reason' => $this->status_reason] : []),
            'status_changed_at' => $this->status_changed_at?->toIso8601ZuluString(),
            'published_version' => $published === null ? null : [
                'id' => $published->id,
                'version' => $published->version,
                'title' => $published->title,
                'published_at' => $published->published_at?->toIso8601ZuluString(),
            ],
            'progress' => $presentedPublished['progress'] ?? $this->progress,
            ...($forImc ? [
                'draft_version' => $draft === null ? null : [
                    'id' => $draft->id,
                    'version' => $draft->version,
                    'revision' => $draft->revision,
                    'title' => $draft->title,
                    'updated_at' => $draft->updated_at?->toIso8601ZuluString(),
                ],
                'readiness_basis' => self::readiness($this->basedOnAssessment),
            ] : []),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
            ...($this->detailed ? [
                'published' => $presentedPublished,
                ...($forImc ? $this->imcDetails($presenter) : []),
            ] : []),
        ];
    }

    /**
     * The draft with its publication review, the factory's current readiness and the
     * level it works at (its assessment's or one it opened through the plan, ADR-026).
     *
     * @return array<string, mixed>
     */
    private function imcDetails(TransformationPlanPresenter $presenter): array
    {
        $eligibility = app(ServiceEligibility::class);
        $current = $eligibility->currentAssessment($this->industrialFactory);
        $draft = $this->draftVersion;

        return [
            'current_readiness' => self::readiness($current),
            'current_level' => $eligibility->levelFor($this->industrialFactory),
            'readiness_changed' => $current !== null && $this->based_on_readiness_assessment_id !== null && $current->id !== $this->based_on_readiness_assessment_id,
            'draft' => $draft === null ? null : $presenter->version($this->resource, $draft, TransformationPlanPresenter::AUDIENCE_IMC),
            'review' => $draft === null ? null : app(TransformationPlanReview::class)->problems($this->resource, $draft),
        ];
    }

    /**
     * @return array{assessment_id: int, level: string|null, name_ar: string|null, total_score: int, completed_at: string}|null
     */
    private static function readiness(?ReadinessAssessment $assessment): ?array
    {
        return $assessment === null ? null : [
            'assessment_id' => $assessment->id,
            'level' => $assessment->category?->code->value,
            'name_ar' => $assessment->category?->name_ar,
            'total_score' => $assessment->total_score,
            'completed_at' => $assessment->completed_at->toIso8601ZuluString(),
        ];
    }
}
