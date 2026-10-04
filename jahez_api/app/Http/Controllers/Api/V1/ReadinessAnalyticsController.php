<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListReadinessAssessmentsRequest;
use App\Http\Resources\V1\ReadinessAssessmentResource;
use App\Models\Factory;
use App\Models\ReadinessAssessment;
use App\Models\ReadinessQuestionnaire;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * IMC views of the digital readiness assessments across factories (ADR-021): the
 * submitted results and their analytics. Everything is read from the stored assessments,
 * whose score and category the server computed when they were submitted (ADR-018);
 * nothing here recomputes or changes them.
 */
class ReadinessAnalyticsController extends Controller
{
    /**
     * Submitted assessments, newest first by default. `filter[current]` keeps each
     * factory's latest one only: its current classification.
     */
    public function index(ListReadinessAssessmentsRequest $request): AnonymousResourceCollection
    {
        [$column, $direction] = $request->sortColumnAndDirection();

        $assessments = ReadinessAssessment::query()
            ->with(['questionnaire', 'category', 'submittedBy', 'industrialFactory'])
            ->when($request->boolean('filter.current'), fn (Builder $query) => $query->whereNotExists(self::newerAssessment(...)))
            ->when($request->input('filter.category'), fn (Builder $query, string $code) => $query->whereHas('category', fn (Builder $categories) => $categories->where('code', $code)))
            ->when($request->input('filter.version'), fn (Builder $query) => $query->whereHas('questionnaire', fn (Builder $versions) => $versions->where('version', $request->integer('filter.version'))))
            ->when($request->fromTime(), fn (Builder $query, $from) => $query->where('completed_at', '>=', $from))
            ->when($request->toTime(), fn (Builder $query, $to) => $query->where('completed_at', '<=', $to))
            ->when($request->filled('filter.score_min'), fn (Builder $query) => $query->where('total_score', '>=', $request->integer('filter.score_min')))
            ->when($request->filled('filter.score_max'), fn (Builder $query) => $query->where('total_score', '<=', $request->integer('filter.score_max')))
            ->when($request->filled('filter.factory'), fn (Builder $query) => $query->where('factory_id', $request->integer('filter.factory')))
            ->when($request->input('search'), fn (Builder $query, string $term) => $query->whereHas('industrialFactory', fn (Builder $factories) => $factories->nameContains($term)))
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->paginate($request->perPage())
            ->withQueryString();

        return ReadinessAssessmentResource::collection($assessments);
    }

    /**
     * Assessment analytics. Definitions:
     *
     * - `factories_total`: every registered factory.
     * - `factories_assessed`: factories with at least one completed assessment. An
     *   assessment is submitted whole, so a factory without one has not completed it
     *   (`factories_not_assessed`): there are no partly saved assessments.
     * - `completion_rate_percent`: factories_assessed / factories_total × 100, one
     *   decimal; null when there are no factories.
     * - `current`: each factory's latest assessment (its classification): counts and
     *   average score per category, and the overall average.
     * - `period`: submissions completed in [from, to] (default: the last twelve months),
     *   per month and per questionnaire version, and their average score.
     * - `definition`: the current questionnaire as stored, with its structure checks.
     */
    public function summary(ListReadinessAssessmentsRequest $request): JsonResponse
    {
        $from = $request->fromTime() ?? now()->subMonthsNoOverflow(11)->startOfMonth();
        $to = $request->toTime() ?? now();

        $factoriesTotal = Factory::query()->count();
        $assessed = Factory::query()->has('readinessAssessments')->count();

        $current = DB::table('readiness_assessments as a')
            ->join('readiness_categories as c', 'c.id', '=', 'a.readiness_category_id')
            ->whereNotExists(fn (QueryBuilder $query) => self::newerAssessment($query, 'a'))
            ->groupBy('c.code')
            ->selectRaw('c.code AS code, COUNT(*) AS factories, AVG(a.total_score) AS average_score')
            ->get()
            ->keyBy('code');
        $currentAverage = DB::table('readiness_assessments as a')
            ->whereNotExists(fn (QueryBuilder $query) => self::newerAssessment($query, 'a'))
            ->avg('a.total_score');

        $inPeriod = fn (): QueryBuilder => DB::table('readiness_assessments as a')->whereBetween('a.completed_at', [$from, $to]);
        $byMonth = $inPeriod()
            ->selectRaw("DATE_FORMAT(a.completed_at, '%Y-%m') AS month, COUNT(*) AS submissions, AVG(a.total_score) AS average_score")
            ->groupBy('month')
            ->orderBy('month')
            ->get();
        $byVersion = $inPeriod()
            ->join('readiness_questionnaires as q', 'q.id', '=', 'a.readiness_questionnaire_id')
            ->selectRaw('q.version AS version, COUNT(*) AS submissions')
            ->groupBy('q.version')
            ->orderBy('q.version')
            ->get();

        $questionnaire = ReadinessQuestionnaire::current();
        $categories = $questionnaire?->categories()->orderBy('min_score')->get() ?? collect();

        return response()->json(['data' => [
            'factories_total' => $factoriesTotal,
            'factories_assessed' => $assessed,
            'factories_not_assessed' => $factoriesTotal - $assessed,
            'completion_rate_percent' => $factoriesTotal === 0 ? null : round($assessed * 100 / $factoriesTotal, 1),
            'assessments_total' => ReadinessAssessment::query()->count(),
            'current' => [
                'average_score' => self::rounded($currentAverage),
                'by_category' => $categories->map(fn ($category): array => [
                    'code' => $category->code->value,
                    'name_ar' => $category->name_ar,
                    'name_en' => $category->name_en,
                    'min_score' => $category->min_score,
                    'max_score' => $category->max_score,
                    'factories' => (int) ($current->get($category->code->value)->factories ?? 0),
                    'average_score' => self::rounded($current->get($category->code->value)->average_score ?? null),
                ])->values()->all(),
            ],
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'submissions' => $inPeriod()->count(),
                'average_score' => self::rounded($inPeriod()->avg('a.total_score')),
                'by_month' => $byMonth->map(fn (object $row): array => [
                    'month' => $row->month,
                    'submissions' => (int) $row->submissions,
                    'average_score' => self::rounded($row->average_score),
                ])->all(),
                'by_version' => $byVersion->map(fn (object $row): array => ['version' => (int) $row->version, 'submissions' => (int) $row->submissions])->all(),
            ],
            'definition' => $questionnaire === null ? null : [
                'version' => $questionnaire->version,
                'pillars' => $questionnaire->pillars()->count(),
                'questions' => $questionnaire->questions()->count(),
                'choices' => DB::table('readiness_choices')->whereIn('readiness_question_id', $questionnaire->questions()->select('id'))->count(),
                'score_range' => $questionnaire->scoreRange(),
                'problems' => $questionnaire->definitionProblems(),
            ],
        ]]);
    }

    /**
     * Restricts an assessment query to rows no later assessment of the same factory
     * supersedes: the factory's current classification (latest completed_at, then id).
     */
    private static function newerAssessment(QueryBuilder $query, string $alias = 'readiness_assessments'): void
    {
        $query->selectRaw('1')
            ->from('readiness_assessments as newer')
            ->whereColumn('newer.factory_id', "{$alias}.factory_id")
            ->where(fn (QueryBuilder $later) => $later
                ->whereColumn('newer.completed_at', '>', "{$alias}.completed_at")
                ->orWhere(fn (QueryBuilder $same) => $same
                    ->whereColumn('newer.completed_at', "{$alias}.completed_at")
                    ->whereColumn('newer.id', '>', "{$alias}.id")));
    }

    private static function rounded(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 1);
    }
}
