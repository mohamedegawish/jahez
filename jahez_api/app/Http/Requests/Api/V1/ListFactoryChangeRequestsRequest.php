<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Factory;
use Illuminate\Support\Facades\Gate;

/**
 * The IMC queue of factory change requests (ADR-020), with the same filter as the
 * provider queue. Authorization runs first, so members get 403 before validation.
 */
class ListFactoryChangeRequestsRequest extends ListProviderChangeRequestsRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewChangeRequestQueue', Factory::class);
    }
}
