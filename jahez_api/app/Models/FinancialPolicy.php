<?php

namespace App\Models;

use App\Enums\FinancialPolicyKind;
use App\Enums\FinancialPolicyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One kind of financial or contract rule for one scope (ADR-023). It holds no value:
 * the values are in its versions, which are drafted, approved by a second administrator
 * and never edited once approved. The kind and scope never change.
 *
 * @property int $id
 * @property FinancialPolicyKind $kind
 * @property FinancialPolicyScope $scope_type
 * @property int|null $scope_id
 * @property string $scope_key
 * @property string $name_ar
 * @property string|null $description_ar
 * @property int $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FinancialPolicy extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => FinancialPolicyKind::class,
            'scope_type' => FinancialPolicyScope::class,
            'scope_id' => 'integer',
            'created_by_user_id' => 'integer',
        ];
    }

    /**
     * Every version, newest first.
     *
     * @return HasMany<FinancialPolicyVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(FinancialPolicyVersion::class)->orderByDesc('version');
    }

    /**
     * @return BelongsTo<Sector, $this>
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'scope_id');
    }

    /**
     * @return BelongsTo<CatalogService, $this>
     */
    public function catalogService(): BelongsTo
    {
        return $this->belongsTo(CatalogService::class, 'scope_id');
    }

    /**
     * @return BelongsTo<ServiceProvider, $this>
     */
    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class, 'scope_id');
    }

    /**
     * The scope's display name: the sector, service or provider name, or the global label.
     */
    public function scopeName(): string
    {
        return match ($this->scope_type) {
            FinancialPolicyScope::Global => FinancialPolicyScope::Global->labelAr(),
            FinancialPolicyScope::Sector => (string) $this->sector?->name_ar,
            FinancialPolicyScope::CatalogService => (string) $this->catalogService?->name_ar,
            FinancialPolicyScope::ServiceProvider => (string) $this->serviceProvider?->name,
        };
    }
}
