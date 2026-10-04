<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserPermissionGrant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Grants and revokes the permissions no role holds (ADR-023): preparing financial
 * policies, approving them and recording manual payments. They are given to named IMC
 * administrators from the server console, so that no administrator can give them to
 * themselves through the API, and the same person need not prepare and approve. Each
 * change is audited with its reason.
 */
class ManagePermissionGrants extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jahez:permissions
                            {action : grant, revoke or list}
                            {email? : The IMC administrator\'s email address (grant, revoke)}
                            {permission? : financial_policies.manage, financial_policies.approve or payments.record}
                            {--reason= : Why the permission is granted or revoked (required for grant and revoke)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Grant, revoke or list the individually granted financial permissions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = (string) $this->argument('action');

        if ($action === 'list') {
            $this->table(['Email', 'Permission', 'Granted at', 'Reason'], UserPermissionGrant::query()->with('user')->orderBy('id')->get()->map(fn (UserPermissionGrant $grant): array => [
                (string) $grant->user?->email, $grant->permission, (string) $grant->created_at?->toIso8601ZuluString(), $grant->reason,
            ])->all());

            return self::SUCCESS;
        }

        if (! in_array($action, ['grant', 'revoke'], true)) {
            $this->components->error('The action must be grant, revoke or list.');

            return self::FAILURE;
        }

        $permission = Permission::tryFrom((string) $this->argument('permission'));
        $reason = trim((string) $this->option('reason'));
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        $error = match (true) {
            $permission === null || ! in_array($permission, Permission::grantedIndividually(), true) => 'The permission must be one of: '.implode(', ', array_map(fn (Permission $granted): string => $granted->value, Permission::grantedIndividually())).'.',
            $user === null => 'No account has this email address.',
            $user->role !== Role::ImcAdmin => 'Only IMC administrators can hold these permissions.',
            $action === 'grant' && ! $user->isActive() => 'The account is deactivated.',
            mb_strlen($reason) < 3 => 'Give a --reason of at least 3 characters.',
            default => null,
        };

        if ($error !== null) {
            $this->components->error($error);

            return self::FAILURE;
        }

        /** @var Permission $permission */
        /** @var User $user */
        $changed = DB::transaction(function () use ($action, $permission, $user, $reason): bool {
            $existing = UserPermissionGrant::query()->where('user_id', $user->id)->where('permission', $permission->value)->lockForUpdate()->first();

            if ($action === 'grant') {
                if ($existing !== null) {
                    return false;
                }
                $grant = new UserPermissionGrant;
                $grant->user_id = $user->id;
                $grant->permission = $permission->value;
                $grant->reason = $reason;
                $grant->save();
                AuditLog::record(AuditEvent::PermissionGranted, subject: $user, metadata: ['permission' => $permission->value, 'reason' => $reason]);

                return true;
            }

            if ($existing === null) {
                return false;
            }
            $existing->delete();
            AuditLog::record(AuditEvent::PermissionRevoked, subject: $user, metadata: ['permission' => $permission->value, 'reason' => $reason]);

            return true;
        });

        $this->components->info(match (true) {
            ! $changed && $action === 'grant' => "{$user->email} already holds {$permission->value}.",
            ! $changed => "{$user->email} does not hold {$permission->value}.",
            $action === 'grant' => "Granted {$permission->value} to {$user->email}.",
            default => "Revoked {$permission->value} from {$user->email}.",
        });

        return self::SUCCESS;
    }
}
