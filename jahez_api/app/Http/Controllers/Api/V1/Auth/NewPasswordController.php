<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class NewPasswordController extends Controller
{
    public const INVALID_LINK_MESSAGE = 'This password reset link is invalid or has expired.';

    /**
     * Set a new password from a reset or invitation link. On success every existing
     * token of the account is revoked and the email address counts as verified.
     *
     * Any failure (unknown email, deactivated account, wrong, used or expired token) gets
     * one generic message. An unknown email still costs one hash comparison, matching the
     * token check a known email gets, so timing does not reveal the account either.
     *
     * The new password, the revoked tokens, the used link and the audit entry are
     * committed together, so a failure leaves the old password and the link in place.
     */
    public function __invoke(ResetPasswordRequest $request): JsonResponse
    {
        $status = DB::transaction(fn (): mixed => Password::broker()->reset(
            $request->safe()->only(['email', 'password', 'password_confirmation', 'token']),
            function (User $user, string $password): void {
                if (! $user->isActive()) {
                    throw ValidationException::withMessages(['token' => [self::INVALID_LINK_MESSAGE]]);
                }

                $user->password = $password;
                $user->email_verified_at ??= now();
                $user->save();

                $user->tokens()->delete();

                AuditLog::record(AuditEvent::PasswordResetCompleted, $user, $user);
            },
        ));

        if ($status === PasswordBroker::INVALID_USER) {
            Hash::check($request->string('token')->toString(), LoginRequest::UNMATCHABLE_PASSWORD_HASH);
        }

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => [self::INVALID_LINK_MESSAGE]]);
        }

        return response()->json(['data' => ['message' => 'Your password has been set.']]);
    }
}
