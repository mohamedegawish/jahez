<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PublishTransformationPlanRequest;
use App\Http\Requests\Api\V1\SaveTransformationPlanDraftRequest;
use App\Http\Resources\V1\TransformationPlanResource;
use App\Models\TransformationPlan;
use App\Models\TransformationPlanStage;
use App\Models\TransformationPlanStageItem;
use App\Models\TransformationPlanVersion;
use App\Models\User;
use App\TransformationPlans\TransformationPlanDraft;
use App\TransformationPlans\TransformationPlanPresenter;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The versions of a transformation plan (ADR-025): the history IMC reads, and the draft it
 * starts, saves, discards and publishes. A published version is never changed.
 */
class TransformationPlanVersionController extends Controller
{
    public function __construct(private readonly TransformationPlanDraft $draft) {}

    /**
     * Every version, newest first, with who drafted, changed and published it: IMC only.
     */
    public function index(TransformationPlan $transformationPlan): JsonResponse
    {
        Gate::authorize('viewVersions', $transformationPlan);

        $versions = $transformationPlan->versions()->with(['createdBy', 'updatedBy', 'publishedBy'])->get()->sortByDesc('version')->values();
        $stageCounts = TransformationPlanStage::query()->whereIn('transformation_plan_version_id', $versions->modelKeys())->selectRaw('transformation_plan_version_id, COUNT(*) AS n')->groupBy('transformation_plan_version_id')->pluck('n', 'transformation_plan_version_id');
        $itemCounts = TransformationPlanStageItem::query()->whereIn('transformation_plan_version_id', $versions->modelKeys())->selectRaw('transformation_plan_version_id, COUNT(*) AS n')->groupBy('transformation_plan_version_id')->pluck('n', 'transformation_plan_version_id');

        return response()->json(['data' => $versions->map(fn (TransformationPlanVersion $version): array => [
            'id' => $version->id,
            'version' => $version->version,
            'status' => $version->status->value,
            'title' => $version->title,
            'revision' => $version->revision,
            'change_note' => $version->change_note,
            'based_on_version_id' => $version->based_on_version_id,
            'stage_count' => (int) ($stageCounts[$version->id] ?? 0),
            'item_count' => (int) ($itemCounts[$version->id] ?? 0),
            'created_by' => $version->createdBy === null ? null : ['id' => $version->createdBy->id, 'name' => $version->createdBy->name],
            'updated_by' => $version->updatedBy === null ? null : ['id' => $version->updatedBy->id, 'name' => $version->updatedBy->name],
            'published_by' => $version->publishedBy === null ? null : ['id' => $version->publishedBy->id, 'name' => $version->publishedBy->name],
            'created_at' => $version->created_at?->toIso8601ZuluString(),
            'updated_at' => $version->updated_at?->toIso8601ZuluString(),
            'published_at' => $version->published_at?->toIso8601ZuluString(),
            'superseded_at' => $version->superseded_at?->toIso8601ZuluString(),
        ])->all()]);
    }

    /**
     * One version as IMC sees it, including a superseded one.
     */
    public function show(TransformationPlan $transformationPlan, TransformationPlanVersion $transformationPlanVersion, TransformationPlanPresenter $presenter): JsonResponse
    {
        Gate::authorize('viewVersions', $transformationPlan);

        if ($transformationPlanVersion->transformation_plan_id !== $transformationPlan->id) {
            abort(404);
        }

        return response()->json(['data' => $presenter->version($transformationPlan, $transformationPlanVersion, TransformationPlanPresenter::AUDIENCE_IMC)]);
    }

    /**
     * Start a draft from the published version (409 when one exists or the plan is closed).
     */
    public function startDraft(TransformationPlan $transformationPlan, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('manage', $transformationPlan);

        $this->draft->startDraft($transformationPlan, $user);

        return TransformationPlanController::detailed($transformationPlan)->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /**
     * Replace the draft's structure (409 for a stale revision, 422 for a structure that
     * cannot be stored).
     */
    public function saveDraft(SaveTransformationPlanDraftRequest $request, TransformationPlan $transformationPlan, #[CurrentUser] User $user): TransformationPlanResource
    {
        $this->draft->save($transformationPlan, $request->definition(), $request->basedOnRevision(), $user);

        return TransformationPlanController::detailed($transformationPlan);
    }

    public function discardDraft(TransformationPlan $transformationPlan, #[CurrentUser] User $user): TransformationPlanResource
    {
        Gate::authorize('manage', $transformationPlan);

        $this->draft->discard($transformationPlan, $user);

        return TransformationPlanController::detailed($transformationPlan);
    }

    /**
     * Publish the draft to the factory (422 while a blocking problem remains).
     */
    public function publish(PublishTransformationPlanRequest $request, TransformationPlan $transformationPlan, #[CurrentUser] User $user): TransformationPlanResource
    {
        $this->draft->publish($transformationPlan, $user, $request->changeNote());

        return TransformationPlanController::detailed($transformationPlan);
    }
}
