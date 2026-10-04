<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\PaymentGatewayManager;
use App\Billing\PaymentProcessor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The payment gateway's callback (webhook), public but verified by the gateway adapter
 * (ADR-017). A key other than the configured gateway's is 404; an unverifiable callback
 * is 400 and changes nothing. A verified one is always answered 200 with its outcome,
 * so the gateway stops retrying, even when it changed nothing (duplicate, unknown
 * payment, amount mismatch, impossible transition).
 */
class PaymentCallbackController extends Controller
{
    public function __invoke(Request $request, string $gateway): JsonResponse
    {
        $adapter = PaymentGatewayManager::forKey($gateway) ?? abort(404);
        $event = $adapter->parseCallback($request);

        return response()->json(['data' => [
            'outcome' => PaymentProcessor::apply($event, $gateway, PaymentProcessor::SOURCE_CALLBACK),
        ]]);
    }
}
