<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The requesting factory withdraws its request from one provider.
 */
class WithdrawProviderRequestRequest extends ActionReasonRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('withdraw', $this->route('providerRequest'));
    }
}
