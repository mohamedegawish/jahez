<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * A party withdraws a contract draft.
 */
class CancelContractRequest extends ActionReasonRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('cancel', $this->route('contract'));
    }
}
