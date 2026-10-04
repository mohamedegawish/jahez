<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An IMC promotion (إعلان) of a provider's listing of a catalog service (ADR-020). It
 * only orders and labels a listing that a factory may already see; eligibility, provider
 * approval and permissions are decided without it. Never deleted: ending it sets
 * ended_at.
 *
 * @property int $id
 * @property int $service_provider_id
 * @property int $catalog_service_id
 * @property string|null $headline
 * @property int $priority
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $ended_at
 * @property int|null $created_by_user_id
 * @property int|null $ended_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ServicePromotion extends Model
{
    public const STATE_ACTIVE = 'active';

    public const STATE_SCHEDULED = 'scheduled';

    public const STATE_EXPIRED = 'expired';

    public const STATE_ENDED = 'ended';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_provider_id' => 'integer',
            'catalog_service_id' => 'integer',
            'priority' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'ended_at' => 'datetime',
            'created_by_user_id' => 'integer',
            'ended_by_user_id' => 'integer',
        ];
    }

    /**
     * Promotions running now: started, not past their end and not ended by IMC.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function running(Builder $query): void
    {
        $now = now();
        $query->whereNull('ended_at')
            ->where('starts_at', '<=', $now)
            ->where(fn (Builder $end) => $end->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    public function state(): string
    {
        return match (true) {
            $this->ended_at !== null => self::STATE_ENDED,
            $this->starts_at->isFuture() => self::STATE_SCHEDULED,
            $this->ends_at !== null && ! $this->ends_at->isFuture() => self::STATE_EXPIRED,
            default => self::STATE_ACTIVE,
        };
    }

    /**
     * @return BelongsTo<ServiceProvider, $this>
     */
    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class);
    }

    /**
     * @return BelongsTo<CatalogService, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(CatalogService::class, 'catalog_service_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
