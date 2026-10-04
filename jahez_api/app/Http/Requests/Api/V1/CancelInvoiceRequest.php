<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The issuer withdraws a draft invoice.
 */
class CancelInvoiceRequest extends ActionReasonRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->route('invoice'));
    }
}
