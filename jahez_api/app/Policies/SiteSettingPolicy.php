<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Landing-page content (texts and the brand logo) is managed by IMC
 * administrators with site_settings.manage. Everyone else reads the public map
 * without a token; to them the administration endpoints do not exist.
 */
class SiteSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::SiteSettingsManage);
    }

    public function update(User $user, SiteSetting $siteSetting): Response
    {
        return $user->hasPermission(Permission::SiteSettingsManage) ? Response::allow() : Response::denyAsNotFound();
    }
}
