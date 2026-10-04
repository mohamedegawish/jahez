<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Paging through a negotiation's messages. Authorization runs before validation, so a
 * thread the user may not see is reported as not found even when the paging input is
 * invalid.
 */
class ListNegotiationMessagesRequest extends ListRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('negotiate', $this->route('providerRequest'));
    }
}
