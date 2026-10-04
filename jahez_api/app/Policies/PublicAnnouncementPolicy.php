<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PublicAnnouncement;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Landing-page announcements (ADR-022) are managed by IMC administrators with
 * announcements.manage. Everyone else reads only live announcements, through the
 * public endpoints; to them a draft does not exist.
 */
class PublicAnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::AnnouncementsManage);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::AnnouncementsManage);
    }

    public function update(User $user, PublicAnnouncement $announcement): Response
    {
        return $user->hasPermission(Permission::AnnouncementsManage) ? Response::allow() : Response::denyAsNotFound();
    }
}
