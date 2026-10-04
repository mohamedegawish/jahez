<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProviderEvaluationRequest;
use App\Http\Resources\V1\ProviderEvaluationResource;
use App\Models\AuditLog;
use App\Models\ProviderEvaluation;
use App\Models\ProviderEvaluationScore;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * IMC evaluations of a provider against the DOC §6 criteria (OQ-13 interim). They inform
 * the manual approval decision; they never approve or reject a provider by themselves.
 */
class ProviderEvaluationController extends Controller
{
    private const RELATIONS = ['scores.criterion', 'recordedBy'];

    /**
     * The provider's evaluations, latest evaluation date first. Not paginated: a provider
     * has few evaluations.
     */
    public function index(ServiceProvider $serviceProvider): AnonymousResourceCollection
    {
        Gate::authorize('evaluate', $serviceProvider);

        return ProviderEvaluationResource::collection(
            $serviceProvider->evaluations()
                ->with(self::RELATIONS)
                ->orderByDesc('evaluated_on')
                ->orderByDesc('id')
                ->get()
        );
    }

    public function store(StoreProviderEvaluationRequest $request, ServiceProvider $serviceProvider, #[CurrentUser] User $actor): JsonResponse
    {
        $criteria = $request->criteria();
        $scaleMax = StoreProviderEvaluationRequest::scaleMax();

        $notes = [];
        $scores = [];
        $weightedScores = [];
        foreach ($criteria as $criterion) {
            $notes[$criterion->code] = $request->string("criteria.{$criterion->code}.note")->toString();
            $scores[$criterion->code] = $scaleMax === null ? null : $request->string("criteria.{$criterion->code}.score")->toString();
            $weightedScores[] = ['weight' => (string) $criterion->weight_percent, 'score' => (string) $scores[$criterion->code]];
        }

        $evaluation = DB::transaction(function () use ($request, $serviceProvider, $actor, $criteria, $scaleMax, $notes, $scores, $weightedScores): ProviderEvaluation {
            $evaluation = new ProviderEvaluation;
            $evaluation->service_provider_id = $serviceProvider->id;
            $evaluation->criteria_version = (int) $criteria->first()?->version;
            $evaluation->summary = $request->string('summary')->toString();
            $evaluation->evaluated_on = $request->date('evaluated_on') ?? now();
            $evaluation->scale_max = $scaleMax;
            $evaluation->weighted_total = $scaleMax === null ? null : ProviderEvaluation::weightedTotal($weightedScores, $scaleMax);
            $evaluation->pass_mark = $scaleMax === null ? null : StoreProviderEvaluationRequest::passMark();
            $evaluation->recorded_by_user_id = $actor->id;
            $evaluation->save();

            foreach ($criteria as $criterion) {
                $score = new ProviderEvaluationScore;
                $score->provider_evaluation_id = $evaluation->id;
                $score->evaluation_criterion_id = $criterion->id;
                $score->score = $scores[$criterion->code];
                $score->note = $notes[$criterion->code];
                $score->save();
            }

            AuditLog::record(AuditEvent::ServiceProviderEvaluationRecorded, $actor, $serviceProvider, [
                'evaluation_id' => $evaluation->id,
                'criteria_version' => $evaluation->criteria_version,
                'weighted_total' => $evaluation->weighted_total,
            ]);

            return $evaluation;
        });

        return (new ProviderEvaluationResource($evaluation->refresh()->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
