<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\BillingPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BillingConfigurationController extends Controller
{
    /**
     * Which billing operations are available, and the open question each blocked one
     * waits for (ADR-017), so clients can hide or explain them.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => BillingPolicy::summary()]);
    }
}
