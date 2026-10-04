<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ReadinessQuestionnaireResource;
use App\Models\ReadinessQuestionnaire;

/**
 * The current digital readiness questionnaire (ADR-018), for any signed-in account: the
 * source text of its questions grouped by pillar, the choices and their points, and the
 * categories with their score ranges and roadmaps.
 */
class ReadinessQuestionnaireController extends Controller
{
    public function show(): ReadinessQuestionnaireResource
    {
        return new ReadinessQuestionnaireResource(
            ReadinessQuestionnaire::query()
                ->where('is_current', true)
                ->with(['pillars.questions.choices', 'categories.recommendations.services.category'])
                ->firstOrFail()
        );
    }
}
