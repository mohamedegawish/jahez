<?php

namespace App\Models;

use App\Enums\PlanItemExecutionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * One catalog service in a transformation plan, with the execution status IMC records
 * (ADR-025). It belongs to the plan, not to a version, so its status, dates and linked
 * service requests survive a new version.
 *
 * @property int $id
 * @property int $transformation_plan_id
 * @property int $catalog_service_id
 * @property PlanItemExecutionStatus $execution_status
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property string|null $status_reason
 * @property int|null $status_changed_by_user_id
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TransformationPlan $plan
 * @property-read CatalogService $service
 */
class TransformationPlanItem extends Model
{
    /**
     * Mirrors the column defaults so a model that was just created can be read without
     * reloading it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'execution_status' => 'not_started',
        'started_at' => null,
        'completed_at' => null,
        'status_reason' => null,
        'status_changed_by_user_id' => null,
        'status_changed_at' => null,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transformation_plan_id' => 'integer',
            'catalog_service_id' => 'integer',
            'execution_status' => PlanItemExecutionStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'status_changed_by_user_id' => 'integer',
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TransformationPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(TransformationPlan::class, 'transformation_plan_id');
    }

    /**
     * @return BelongsTo<CatalogService, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(CatalogService::class, 'catalog_service_id');
    }

    /**
     * Every service request sent for the item, oldest first.
     *
     * @return HasMany<ServiceRequest, $this>
     */
    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class)->orderBy('id');
    }

    /**
     * @return HasMany<TransformationPlanStageItem, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(TransformationPlanStageItem::class);
    }

    /**
     * Apply an execution change the workflow allows, or refuse it with 409. The start
     * and completion dates are set once, when the item first starts or completes.
     */
    public function moveTo(PlanItemExecutionStatus $next, User $actor, ?string $reason = null): void
    {
        if (! $this->execution_status->canBecome($next)) {
            throw new ConflictHttpException("This plan item is {$this->execution_status->value} and cannot become {$next->value}.");
        }

        if ($next === PlanItemExecutionStatus::InProgress && $this->started_at === null) {
            $this->started_at = now();
        }

        if ($next === PlanItemExecutionStatus::Completed) {
            $this->completed_at = now();
        }

        $this->execution_status = $next;
        $this->status_reason = $reason;
        $this->status_changed_by_user_id = $actor->id;
        $this->status_changed_at = now();
        $this->save();
    }
}
