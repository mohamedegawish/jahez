<?php

namespace App\Models;

use App\Enums\ServiceRequestStatus;
use Database\Factories\ServiceRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A factory's request for one catalog service, sent to one or more providers (ADR-015).
 * Ownership, status and author are set by the server, never mass-assigned.
 *
 * @property int $id
 * @property int $factory_id
 * @property int $catalog_service_id
 * @property int|null $transformation_plan_item_id
 * @property string $title
 * @property string $need
 * @property string|null $requirements
 * @property ServiceRequestStatus $status
 * @property Carbon|null $status_changed_at
 * @property int $created_by_user_id
 */
class ServiceRequest extends Model
{
    /** @use HasFactory<ServiceRequestFactory> */
    use HasFactory;

    /**
     * Mirrors the column defaults so a model that was just created can be read without
     * reloading it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
        'status_changed_at' => null,
        'requirements' => null,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'factory_id' => 'integer',
            'catalog_service_id' => 'integer',
            'transformation_plan_item_id' => 'integer',
            'created_by_user_id' => 'integer',
            'status' => ServiceRequestStatus::class,
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Factory, $this>
     */
    public function industrialFactory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
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

    /**
     * The transformation plan item the request was sent for, if any (ADR-025).
     *
     * @return BelongsTo<TransformationPlanItem, $this>
     */
    public function planItem(): BelongsTo
    {
        return $this->belongsTo(TransformationPlanItem::class, 'transformation_plan_item_id');
    }

    /**
     * @return HasMany<ProviderRequest, $this>
     */
    public function providerRequests(): HasMany
    {
        return $this->hasMany(ProviderRequest::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === ServiceRequestStatus::Open;
    }

    /**
     * Apply a status change the workflow allows, or refuse it with 409. Callers check the
     * status first to give a message that fits the action; this is the last guard.
     */
    public function moveTo(ServiceRequestStatus $next): void
    {
        if (! $this->status->canBecome($next)) {
            throw new ConflictHttpException("This service request is {$this->status->value} and cannot become {$next->value}.");
        }

        $this->status = $next;
        $this->status_changed_at = now();
        $this->save();
    }
}
