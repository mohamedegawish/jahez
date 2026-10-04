<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\User;
use Illuminate\Validation\Validator;

/**
 * filter[recommended] narrows a list to what the readiness roadmap recommends for the
 * signed-in member's factory (ADR-018), so it needs a factory member whose factory has
 * completed a readiness assessment.
 */
trait ValidatesRecommendedFilter
{
    public const RECOMMENDED_NEEDS_FACTORY = 'Only factory members can list what is recommended for their factory.';

    public const RECOMMENDED_NEEDS_ASSESSMENT = 'Complete your factory\'s readiness assessment to see its recommendations.';

    protected function validateRecommendedFilter(Validator $validator): void
    {
        if ($validator->errors()->has('filter.recommended') || ! $this->boolean('filter.recommended')) {
            return;
        }

        $user = $this->user();
        $factory = $user instanceof User ? $user->industrialFactory : null;

        if ($factory === null) {
            $validator->errors()->add('filter.recommended', self::RECOMMENDED_NEEDS_FACTORY);
        } elseif (! $factory->readinessAssessments()->exists()) {
            $validator->errors()->add('filter.recommended', self::RECOMMENDED_NEEDS_ASSESSMENT);
        }
    }
}
