<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListRequest;
use App\Http\Requests\Api\V1\StoreReadinessAssessmentRequest;
use App\Http\Resources\V1\ReadinessAssessmentResource;
use App\Models\Factory;
use App\Models\ReadinessAssessment;
use App\Models\User;
use App\Readiness\ReadinessAssessmentRecorder;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Digital readiness assessments of a factory (ADR-018). The factory's members submit
 * them; the server scores and classifies each one. Every assessment is kept, and the
 * latest one is the factory's current classification.
 */
class ReadinessAssessmentController extends Controller
{
    /**
     * Relations of the summary shown in the history.
     */
    private const SUMMARY = ['questionnaire', 'category', 'submittedBy'];

    /**
     * Further relations of the full result.
     */
    private const RESULT = ['answers', 'questionnaire.pillars.questions.choices', 'category.recommendations.services.category'];

    /**
     * The factory's assessments, latest first.
     */
    public function index(ListRequest $request, Factory $factory): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ReadinessAssessment::class, $factory]);

        return ReadinessAssessmentResource::collection(
            $factory->readinessAssessments()
                ->with(self::SUMMARY)
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->paginate($request->perPage())
                ->withQueryString()
        );
    }

    /**
     * Scores and stores the submission through `ReadinessAssessmentRecorder`, the one
     * place that classifies assessments (the demo seeder uses it too).
     *
     * With an `Idempotency-Key` header, a repeated submission (a double click or a
     * retried request) returns the assessment already stored under that key with 200
     * instead of recording a second one. The factory row is locked, so two copies sent
     * at once are stored once.
     */
    public function store(StoreReadinessAssessmentRequest $request, Factory $factory, #[CurrentUser] User $actor, ReadinessAssessmentRecorder $recorder): JsonResponse
    {
        ['assessment' => $assessment, 'created' => $created] = $recorder->record(
            $factory,
            $request->questionnaire(),
            $request->selectedChoices(),
            $actor,
            $request->idempotencyKey(),
        );

        return (new ReadinessAssessmentResource($assessment->load([...self::SUMMARY, ...self::RESULT])))
            ->response()
            ->setStatusCode($created ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK);
    }

    /**
     * One assessment with its pillar breakdown, answers and the roadmap of its category.
     */
    public function show(Factory $factory, ReadinessAssessment $readinessAssessment): ReadinessAssessmentResource
    {
        Gate::authorize('view', $readinessAssessment);

        return new ReadinessAssessmentResource($readinessAssessment->load([...self::SUMMARY, ...self::RESULT]));
    }
}
