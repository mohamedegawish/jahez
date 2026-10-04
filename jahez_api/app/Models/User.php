<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Permission;
use App\Enums\Role;
use App\Notifications\PasswordResetLink;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property Role $role
 * @property int|null $factory_id
 * @property int|null $service_provider_id
 * @property Carbon|null $deactivated_at
 * @property bool $email_notifications
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable. Role, organization links and
     * deactivation are deliberately excluded and only ever set explicitly.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * Mirrors the column default so a model that was just created (and not reloaded)
     * can answer isActive() without reading a missing attribute.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'deactivated_at' => null,
        'email_notifications' => true,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'factory_id' => 'integer',
            'service_provider_id' => 'integer',
            'deactivated_at' => 'datetime',
            'email_notifications' => 'boolean',
        ];
    }

    /**
     * The factory this user belongs to. Not named factory() because HasFactory
     * already defines the static factory() method on every model.
     *
     * @return BelongsTo<Factory, $this>
     */
    public function industrialFactory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }

    /**
     * @return BelongsTo<ServiceProvider, $this>
     */
    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class);
    }

    public function hasPermission(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * The role's permissions and, for an active IMC administrator, the permissions granted
     * to this account individually (ADR-023). A grant to anyone else counts for nothing.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        $permissions = $this->role->permissions();

        if ($this->role !== Role::ImcAdmin || ! $this->isActive() || ! $this->exists) {
            return $permissions;
        }

        foreach ($this->permissionGrants as $grant) {
            $granted = Permission::tryFrom($grant->permission);

            if ($granted !== null && in_array($granted, Permission::grantedIndividually(), true) && ! in_array($granted, $permissions, true)) {
                $permissions[] = $granted;
            }
        }

        return $permissions;
    }

    /**
     * @return HasMany<UserPermissionGrant, $this>
     */
    public function permissionGrants(): HasMany
    {
        return $this->hasMany(UserPermissionGrant::class);
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    public function belongsToFactory(int $factoryId): bool
    {
        return $this->factory_id !== null && $this->factory_id === $factoryId;
    }

    public function belongsToServiceProvider(int $serviceProviderId): bool
    {
        return $this->service_provider_id !== null && $this->service_provider_id === $serviceProviderId;
    }

    /**
     * Whether both users are members of the same factory or the same service provider.
     */
    public function sharesOrganizationWith(User $other): bool
    {
        return ($this->factory_id !== null && $this->factory_id === $other->factory_id)
            || ($this->service_provider_id !== null && $this->service_provider_id === $other->service_provider_id);
    }

    /**
     * Send the password reset link. Called by the password broker from inside the
     * SendPasswordResetLink job, so this already runs in the queue worker.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new PasswordResetLink($token));
    }
}
