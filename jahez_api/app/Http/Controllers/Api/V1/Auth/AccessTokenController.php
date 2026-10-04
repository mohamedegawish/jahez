<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\V1\CurrentUserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues and revokes Sanctum bearer tokens (ADR-003).
 */
class AccessTokenController extends Controller
{
    /**
     * Log in: exchange valid credentials for an expiring bearer token.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $user = $request->authenticate();

        $expiresAt = now()->addMinutes(Config::integer('sanctum.expiration'));

        $token = DB::transaction(function () use ($request, $user, $expiresAt): NewAccessToken {
            $token = $user->createToken($request->string('device_name')->toString(), ['*'], $expiresAt);

            AuditLog::record(AuditEvent::LoginSucceeded, $user, $user, [
                'credential_id' => $token->accessToken->id,
                'device_name' => $token->accessToken->name,
            ]);

            return $token;
        });

        $user->loadMissing(['industrialFactory', 'serviceProvider']);

        return response()->json([
            'data' => [
                'token_type' => 'Bearer',
                'access_token' => $token->plainTextToken,
                'expires_at' => $expiresAt->toIso8601ZuluString(),
                'user' => new CurrentUserResource($user),
            ],
        ]);
    }

    /**
     * Log out: revoke the token used for this request. With cookie authentication
     * disabled (ADR-003) the current token is always a stored personal access token.
     */
    public function destroy(#[CurrentUser] User $user): Response
    {
        $token = $user->currentAccessToken();

        DB::transaction(function () use ($token, $user): void {
            $token->delete();

            AuditLog::record(AuditEvent::LoggedOut, $user, $user, ['credential_id' => $token->getKey()]);
        });

        return response()->noContent();
    }
}
