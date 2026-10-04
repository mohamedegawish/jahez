<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\BillingPolicy;
use App\Http\Controllers\Controller;
use App\Models\Agreement;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AgreementFinancialReadinessController extends Controller
{
    /**
     * For each financial operation on the agreement (contract draft, invoice draft and
     * issue, gateway payment, signature, payouts): whether it is possible today and, if
     * not, every reason in Arabic with the decision it waits for (ADR-023). Whoever may
     * see the agreement may ask.
     */
    public function __invoke(Agreement $agreement): JsonResponse
    {
        Gate::authorize('view', $agreement);

        return response()->json(['data' => BillingPolicy::readiness($agreement->load('review'))]);
    }
}
