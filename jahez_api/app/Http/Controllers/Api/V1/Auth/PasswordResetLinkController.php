<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Jobs\SendPasswordResetLink;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PasswordResetLinkController extends Controller
{
    public const RESPONSE_MESSAGE = 'If an active account exists for this email, a password reset link has been sent.';

    /**
     * Queue a reset link. The request does the same work whether or not the account
     * exists (the lookup and token creation happen in the job), so neither the response
     * nor its timing reveals which emails are registered.
     */
    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        SendPasswordResetLink::dispatch($request->string('email')->toString(), $request->ip());

        return response()->json(['data' => ['message' => self::RESPONSE_MESSAGE]], Response::HTTP_ACCEPTED);
    }
}
