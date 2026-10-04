<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CurrentUserResource;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;

class CurrentUserController extends Controller
{
    /**
     * The authenticated user, their organization and permissions.
     */
    public function __invoke(#[CurrentUser] User $user): CurrentUserResource
    {
        return new CurrentUserResource($user->loadMissing(['industrialFactory', 'serviceProvider']));
    }
}
