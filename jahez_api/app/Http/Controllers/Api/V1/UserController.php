<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListRequest;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Resources\V1\UserResource;
use App\Jobs\SendAccountInvitation;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public const LAST_ADMINISTRATOR_MESSAGE = 'This change would leave the platform without an active IMC administrator.';

    public function index(ListRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()
            ->with(['industrialFactory', 'serviceProvider'])
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return UserResource::collection($users);
    }

    /**
     * Create an account and invite its owner to set a password (ADR-011). The account
     * gets a random password nobody knows until the invitee sets their own.
     */
    public function store(StoreUserRequest $request, #[CurrentUser] User $actor): JsonResponse
    {
        $user = DB::transaction(function () use ($request, $actor): User {
            $user = new User($request->safe()->only(['name', 'email']));
            $user->password = Str::password(64);
            $user->role = $request->role();
            $user->factory_id = $request->factoryId();
            $user->service_provider_id = $request->serviceProviderId();
            $user->save();

            AuditLog::record(AuditEvent::UserCreated, $actor, $user, [
                'role' => $user->role->value,
                'factory_id' => $user->factory_id,
                'service_provider_id' => $user->service_provider_id,
            ]);

            SendAccountInvitation::dispatch($user)->afterCommit();

            return $user;
        });

        return (new UserResource($user->load(['industrialFactory', 'serviceProvider'])))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user->load(['industrialFactory', 'serviceProvider']));
    }

    /**
     * Rename, deactivate or reactivate an account. Deactivation revokes every token
     * and pending password reset link of the account in the same transaction.
     */
    public function update(UpdateUserRequest $request, User $user, #[CurrentUser] User $actor): UserResource
    {
        DB::transaction(function () use ($request, $user, $actor): void {
            if ($request->safe()->has('name') && $user->name !== $request->string('name')->toString()) {
                $previousName = $user->name;
                $user->name = $request->string('name')->toString();
                AuditLog::record(AuditEvent::UserRenamed, $actor, $user, ['from' => $previousName, 'to' => $user->name]);
            }

            if ($request->safe()->has('is_active')) {
                if ($request->boolean('is_active') && ! $user->isActive()) {
                    $user->deactivated_at = null;
                    AuditLog::record(AuditEvent::UserReactivated, $actor, $user);
                } elseif (! $request->boolean('is_active') && $user->isActive()) {
                    if ($user->role === Role::ImcAdmin) {
                        $this->ensureAnotherAdministratorRemainsActive($user, $actor);
                    }

                    $user->deactivated_at = now();
                    $user->tokens()->delete();
                    Password::broker()->deleteToken($user);
                    AuditLog::record(AuditEvent::UserDeactivated, $actor, $user);
                }
            }

            $user->save();
        });

        return new UserResource($user->load(['industrialFactory', 'serviceProvider']));
    }

    /**
     * Locks the active administrators (in id order, so concurrent requests queue rather
     * than deadlock) and refuses the change unless the acting administrator is still
     * active and at least one other administrator stays active. This stops two
     * administrators deactivating each other at the same moment.
     */
    private function ensureAnotherAdministratorRemainsActive(User $administrator, User $actor): void
    {
        $activeAdministratorIds = User::query()
            ->where('role', Role::ImcAdmin)
            ->whereNull('deactivated_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        $remainingActive = $activeAdministratorIds->reject(fn (int $id): bool => $id === $administrator->id);

        if (! $remainingActive->contains($actor->id)) {
            throw ValidationException::withMessages(['is_active' => [self::LAST_ADMINISTRATOR_MESSAGE]]);
        }
    }
}
