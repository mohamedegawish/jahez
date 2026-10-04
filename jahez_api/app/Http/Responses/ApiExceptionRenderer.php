<?php

namespace App\Http\Responses;

use App\Billing\PolicyNotConfiguredException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every exception raised under the API prefix as the standard JSON error
 * envelope documented in docs/api-conventions.md.
 */
class ApiExceptionRenderer
{
    /**
     * Machine-readable error codes per HTTP status.
     *
     * @var array<int, string>
     */
    private const ERROR_CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        413 => 'payload_too_large',
        415 => 'unsupported_media_type',
        419 => 'csrf_token_mismatch',
        422 => 'validation_failed',
        429 => 'too_many_requests',
        503 => 'service_unavailable',
    ];

    /**
     * Statuses whose framework messages can reveal internals (model class names,
     * route URIs, limiter details), so a fixed message is always returned.
     *
     * @var array<int, string>
     */
    private const FIXED_MESSAGES = [
        404 => 'Resource not found.',
        405 => 'Method not allowed.',
        429 => 'Too many requests.',
    ];

    /**
     * Render the exception, or return null to leave it to the framework: non-API
     * requests, and exceptions that already carry their own response.
     */
    public function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api', 'api/*') || $exception instanceof HttpResponseException) {
            return null;
        }

        if ($exception instanceof ValidationException && $exception->response !== null) {
            return null;
        }

        return match (true) {
            $exception instanceof ValidationException => $this->errorResponse(
                $exception->status,
                self::ERROR_CODES[422],
                $exception->getMessage(),
                ['errors' => $exception->errors()],
            ),
            $exception instanceof PolicyNotConfiguredException => $this->errorResponse(
                Response::HTTP_CONFLICT,
                'policy_not_configured',
                $exception->getMessage(),
                array_filter([
                    'decision_needed' => $exception->decisionNeeded,
                    'reason_ar' => $exception->reasonAr,
                    'missing_policies' => $exception->missingPolicies === [] ? null : $exception->missingPolicies,
                ], fn (mixed $value): bool => $value !== null),
            ),
            $exception instanceof AuthenticationException => $this->errorResponse(
                Response::HTTP_UNAUTHORIZED,
                self::ERROR_CODES[401],
                'Unauthenticated.',
            ),
            $exception instanceof HttpExceptionInterface => $this->httpErrorResponse($exception),
            $exception instanceof UniqueConstraintViolationException => $this->errorResponse(
                Response::HTTP_CONFLICT,
                self::ERROR_CODES[409],
                'The request conflicts with an existing record.',
            ),
            default => $this->serverErrorResponse($exception),
        };
    }

    private function httpErrorResponse(HttpExceptionInterface $exception): JsonResponse
    {
        $status = $exception->getStatusCode();

        $message = self::FIXED_MESSAGES[$status]
            ?? ($exception->getMessage() !== '' ? $exception->getMessage() : $this->statusText($status));

        $code = self::ERROR_CODES[$status] ?? ($status >= 500 ? 'server_error' : 'client_error');

        /** @var array<string, string> $headers */
        $headers = $exception->getHeaders();

        return $this->errorResponse($status, $code, $message, [], $headers);
    }

    private function serverErrorResponse(Throwable $exception): JsonResponse
    {
        $debug = config('app.debug') ? [
            'debug' => [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => array_map(
                    fn (array $frame): array => Arr::except($frame, ['args']),
                    $exception->getTrace(),
                ),
            ],
        ] : [];

        return $this->errorResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'server_error', 'Server error.', $debug);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    private function errorResponse(int $status, string $code, string $message, array $details = [], array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'message' => $message,
            'code' => $code,
            ...$details,
            'request_id' => Context::get('request_id'),
        ], $status, $headers);
    }

    private function statusText(int $status): string
    {
        return Response::$statusTexts[$status] ?? 'Error';
    }
}
