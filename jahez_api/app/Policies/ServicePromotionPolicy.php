<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ServicePromotion;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Promotions (ADR-020) are managed by IMC administrators with promotions.manage. Factory
 * and provider members see promotions only as the label on a listing; to them a
 * promotion record does not exist.
 */
class ServicePromotionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::PromotionsManage);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::PromotionsManage);
    }

    public function update(User $user, ServicePromotion $promotion): Response
    {
        return $user->hasPermission(Permission::PromotionsManage) ? Response::allow() : Response::denyAsNotFound();
    }
}
