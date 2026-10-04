<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\FactoryAssessmentResource;
use App\Models\Factory;
use App\Models\FactoryAssessment;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Legacy manual IMC classifications (ADR-016), read-only since the digital readiness
 * assessment replaced them (ADR-018). Nothing records new ones; the factory's current
 * classification now comes from its latest readiness assessment.
 */
class FactoryAssessmentController extends Controller
{
    /**
     * Relations the resource shows.
     */
    private const RELATIONS = ['maturityTier.pathwayLevel.pathway', 'recordedBy'];

    /**
     * The factory's manual classification history, latest assessment date first, and the
     * latest recorded first on the same date. Not paginated: the set is closed.
     */
    public function index(Factory $factory): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [FactoryAssessment::class, $factory]);

        return FactoryAssessmentResource::collection(
            $factory->assessments()->with(self::RELATIONS)->orderByDesc('assessed_on')->orderByDesc('id')->get()
        );
    }
}
