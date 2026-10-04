<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateReadinessQuestionnaireRequest;
use App\Http\Resources\V1\ReadinessQuestionnaireVersionResource;
use App\Models\AuditLog;
use App\Models\ReadinessCategory;
use App\Models\ReadinessChoice;
use App\Models\ReadinessQuestion;
use App\Models\ReadinessQuestionnaire;
use App\Models\ReadinessRecommendation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Versions of the digital readiness questionnaire, for IMC administrators (ADR-018
 * addendum). A published version is never changed, so the results recorded against it
 * stay reproducible: a change is made in a draft, a copy of the current version, and the
 * draft becomes the version factories answer when it is published. Earlier assessments
 * keep their version, answers, total and category.
 *
 * Locks: the draft row, and for publishing every current row too, in id order.
 */
class ReadinessQuestionnaireVersionController extends Controller
{
    private const DEFINITION = ['pillars.questions.choices', 'categories.recommendations.services.category'];

    /**
     * Who drafted, last changed and published each version (ADR-018 addendum 2).
     */
    private const ACTORS = ['createdBy', 'updatedBy', 'publishedBy'];

    public const ONE_DRAFT = 'A draft version already exists. Edit, publish or delete it first.';

    public const NOT_A_DRAFT = 'Version :version has been published and can no longer change. Create a new draft instead.';

    /**
     * Every version, newest first. Versions are few, so the list is not paginated.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('manage', ReadinessQuestionnaire::class);

        return ReadinessQuestionnaireVersionResource::collection(
            ReadinessQuestionnaire::query()->with(self::ACTORS)->withCount('assessments')->orderByDesc('version')->get()
        );
    }

    public function show(ReadinessQuestionnaire $readinessQuestionnaire): ReadinessQuestionnaireVersionResource
    {
        Gate::authorize('manage', ReadinessQuestionnaire::class);

        return new ReadinessQuestionnaireVersionResource($readinessQuestionnaire->loadCount('assessments')->load([...self::DEFINITION, ...self::ACTORS]));
    }

    /**
     * Start a draft from the current version. One draft at a time.
     */
    public function store(#[CurrentUser] User $actor): JsonResponse
    {
        Gate::authorize('manage', ReadinessQuestionnaire::class);

        $draft = DB::transaction(function () use ($actor): ReadinessQuestionnaire {
            $current = ReadinessQuestionnaire::query()->where('is_current', true)->lockForUpdate()->first()
                ?? throw new ConflictHttpException('No readiness questionnaire is current.');

            if (ReadinessQuestionnaire::query()->whereNull('published_at')->where(fn ($query) => $query->whereNull('is_current'))->lockForUpdate()->exists()) {
                throw new ConflictHttpException(self::ONE_DRAFT);
            }

            $draft = $current->copyAsDraft();
            $draft->forceFill(['created_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id])->save();

            AuditLog::record(AuditEvent::ReadinessQuestionnaireDrafted, $actor, $draft, [
                'version' => $draft->version,
                'based_on_version' => $current->version,
            ]);

            return $draft;
        });

        return (new ReadinessQuestionnaireVersionResource($draft->loadCount('assessments')->load([...self::DEFINITION, ...self::ACTORS])))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function update(UpdateReadinessQuestionnaireRequest $request, ReadinessQuestionnaire $readinessQuestionnaire, #[CurrentUser] User $actor): ReadinessQuestionnaireVersionResource
    {
        DB::transaction(function () use ($request, $readinessQuestionnaire, $actor): void {
            $draft = $this->lockDraft($readinessQuestionnaire);
            $definition = $request->definition();
            $draft->replaceDefinition($definition);
            $draft->forceFill(['updated_by_user_id' => $actor->id])->save();

            // When the roadmap was sent: the number of lines per category, never their text.
            $recommendationLines = [];
            foreach ($definition['categories'] as $category) {
                if (array_key_exists('recommendations', $category)) {
                    $recommendationLines[$category['code']] = count($category['recommendations']);
                }
            }

            AuditLog::record(AuditEvent::ReadinessQuestionnaireUpdated, $actor, $draft, [
                'version' => $draft->version,
                'questions' => $draft->questions()->count(),
                'category_ranges' => $draft->categories()->get()->mapWithKeys(fn (ReadinessCategory $category): array => [$category->code->value => [$category->min_score, $category->max_score]])->all(),
                ...($recommendationLines === [] ? [] : ['recommendations' => $recommendationLines]),
            ]);
        });

        return new ReadinessQuestionnaireVersionResource($readinessQuestionnaire->refresh()->loadCount('assessments')->load([...self::DEFINITION, ...self::ACTORS]));
    }

    /**
     * Make the draft the version factories answer. The previous version is retired, not
     * changed: its assessments keep their scores and categories.
     */
    public function publish(ReadinessQuestionnaire $readinessQuestionnaire, #[CurrentUser] User $actor): ReadinessQuestionnaireVersionResource
    {
        Gate::authorize('manage', ReadinessQuestionnaire::class);

        DB::transaction(function () use ($readinessQuestionnaire, $actor): void {
            // Lock the draft and the current version together, in id order.
            ReadinessQuestionnaire::query()
                ->where(fn ($query) => $query->where('is_current', true)->orWhere('id', $readinessQuestionnaire->id))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $draft = $this->lockDraft($readinessQuestionnaire);
            $problems = $draft->definitionProblems();
            if ($problems !== []) {
                throw ValidationException::withMessages(['definition' => $problems]);
            }

            $previous = ReadinessQuestionnaire::query()->where('is_current', true)->lockForUpdate()->first();
            $previous?->forceFill(['is_current' => null])->save();
            $draft->forceFill(['is_current' => true, 'published_at' => now(), 'published_by_user_id' => $actor->id])->save();

            AuditLog::record(AuditEvent::ReadinessQuestionnairePublished, $actor, $draft, [
                'version' => $draft->version,
                'previous_version' => $previous?->version,
            ]);
        });

        return new ReadinessQuestionnaireVersionResource($readinessQuestionnaire->refresh()->loadCount('assessments')->load([...self::DEFINITION, ...self::ACTORS]));
    }

    public function destroy(ReadinessQuestionnaire $readinessQuestionnaire, #[CurrentUser] User $actor): Response
    {
        Gate::authorize('manage', ReadinessQuestionnaire::class);

        DB::transaction(function () use ($readinessQuestionnaire, $actor): void {
            $draft = $this->lockDraft($readinessQuestionnaire);

            $categoryIds = $draft->categories()->pluck('id');
            ReadinessRecommendation::query()->whereIn('readiness_category_id', $categoryIds)->delete();
            ReadinessCategory::query()->whereKey($categoryIds)->delete();
            $questionIds = $draft->questions()->pluck('id');
            ReadinessChoice::query()->whereIn('readiness_question_id', $questionIds)->delete();
            ReadinessQuestion::query()->whereKey($questionIds)->delete();
            $draft->pillars()->delete();
            $draft->delete();

            AuditLog::record(AuditEvent::ReadinessQuestionnaireDeleted, $actor, metadata: ['version' => $draft->version]);
        });

        return response()->noContent();
    }

    private function lockDraft(ReadinessQuestionnaire $questionnaire): ReadinessQuestionnaire
    {
        $locked = ReadinessQuestionnaire::query()->lockForUpdate()->findOrFail($questionnaire->id);

        if (! $locked->isDraft() || $locked->assessments()->exists()) {
            throw new ConflictHttpException(str_replace(':version', (string) $locked->version, self::NOT_A_DRAFT));
        }

        return $locked;
    }
}
