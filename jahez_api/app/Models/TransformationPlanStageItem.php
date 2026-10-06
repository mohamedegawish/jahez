<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Where a plan item sits in one version (ADR-025): its stage and order, the provider IMC
 * assigned (binding for the factory's request; owner decision 2026-10-05), instructions
 * for the factory, IMC's internal notes and planned dates.
 *
 * @property int $id
 * @property int $transformation_plan_version_id
 * @property int $transformation_plan_stage_id
 * @property int $transformation_plan_item_id
 * @property int $position
 * @property int|null $service_provider_id
 * @property string|null $instructions_ar
 * @property string|null $internal_notes
 * @property Carbon|null $planned_start_date
 * @property Carbon|null $planned_end_date
 * @property-read TransformationPlanStage $stage
 * @property-read TransformationPlanItem $item
 * @property-read ServiceProvider|null $assignedProvider
 */
class TransformationPlanStageItem extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transformation_plan_version_id' => 'integer',
            'transformation_plan_stage_id' => 'integer',
            'transformation_plan_item_id' => 'integer',
            'position' => 'integer',
            'service_provider_id' => 'integer',
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<TransformationPlanVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(TransformationPlanVersion::class, 'transformation_plan_version_id');
    }

    /**
     * @return BelongsTo<TransformationPlanStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(TransformationPlanStage::class, 'transformation_plan_stage_id');
    }

    /**
     * @return BelongsTo<TransformationPlanItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(TransformationPlanItem::class, 'transformation_plan_item_id');
    }

    /**
     * @return BelongsTo<ServiceProvider, $this>
     */
    public function assignedProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class, 'service_provider_id');
    }

    /**
     * The placements this one waits for (finish-to-start), in the same version.
     *
     * @return BelongsToMany<TransformationPlanStageItem, $this>
     */
    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'transformation_plan_dependencies', 'stage_item_id', 'depends_on_stage_item_id');
    }
}
