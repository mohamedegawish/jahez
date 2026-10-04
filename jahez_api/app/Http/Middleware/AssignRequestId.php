<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    /**
     * Accepted shape for a client-supplied request ID. Anything else is replaced so
     * the value is always safe to write to logs and echo back in headers.
     */
    private const ACCEPTED_FORMAT = '/\A[A-Za-z0-9._-]{8,128}\z/';

    /**
     * Assign a correlation ID to the request, share it with logs and queued jobs
     * through the Context repository, and return it in the response header.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        Context::add('request_id', $requestId);

        $response = $next($request);

        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        $incomingRequestId = $request->headers->get(self::HEADER);

        if (is_string($incomingRequestId) && preg_match(self::ACCEPTED_FORMAT, $incomingRequestId) === 1) {
            return $incomingRequestId;
        }

        return (string) Str::uuid();
    }
}
