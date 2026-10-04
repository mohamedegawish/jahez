<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The provider declines a request, before or during the negotiation.
 */
class DeclineProviderRequestRequest extends ActionReasonRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('respond', $this->route('providerRequest'));
    }
}
