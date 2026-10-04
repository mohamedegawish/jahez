<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The requesting factory cancels its service request.
 */
class CancelServiceRequestRequest extends ActionReasonRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('cancel', $this->route('serviceRequest'));
    }
}
