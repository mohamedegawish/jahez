<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HealthController extends Controller
{
    /**
     * Readiness check: reports whether the application can reach its database.
     * Failure details are reported to the log only, never returned to the client.
     */
    public function __invoke(): JsonResponse
    {
        $databaseIsReachable = $this->databaseIsReachable();

        return response()->json([
            'data' => [
                'status' => $databaseIsReachable ? 'ok' : 'unavailable',
                'checks' => [
                    'database' => $databaseIsReachable ? 'ok' : 'failed',
                ],
            ],
        ], $databaseIsReachable ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function databaseIsReachable(): bool
    {
        try {
            DB::connection()->select('select 1');

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
