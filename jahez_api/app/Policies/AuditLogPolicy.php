<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * The audit log is readable only with the audit permission and is never writable
 * through the API.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::AuditLogsView);
    }
}
