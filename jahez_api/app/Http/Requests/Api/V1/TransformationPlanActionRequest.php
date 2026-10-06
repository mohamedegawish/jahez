<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * An IMC action on a transformation plan or one of its items (ADR-025), with an optional
 * reason. Authorization runs before validation, so a plan the user may not see is
 * reported as not found.
 */
class TransformationPlanActionRequest extends ActionReasonRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->route('transformationPlan'));
    }
}
