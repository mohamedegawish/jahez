<?php

namespace App\Policies;

use App\Models\ServiceCartItem;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The cart of a factory member (ADR-027). Only factory members have a cart, because only
 * they send service requests (ServiceRequestPolicy::create). Each member's cart is their
 * own: another account's item is reported as not found.
 */
class ServiceCartItemPolicy
{
    /**
     * Read, fill, clear and check out one's own cart: factory members.
     */
    public function viewAny(User $user): bool
    {
        return $user->factory_id !== null;
    }

    public function create(User $user): bool
    {
        return $user->factory_id !== null;
    }

    public function update(User $user, ServiceCartItem $item): Response
    {
        return $this->own($user, $item);
    }

    public function delete(User $user, ServiceCartItem $item): Response
    {
        return $this->own($user, $item);
    }

    private function own(User $user, ServiceCartItem $item): Response
    {
        return $user->factory_id !== null && $item->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
