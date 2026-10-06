<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A stage (مرحلة) of one plan version, in the order IMC chose (ADR-025).
 * `internal_notes` is never shown to the factory.
 *
 * @property int $id
 * @property int $transformation_plan_version_id
 * @property int $position
 * @property string $name_ar
 * @property string|null $objective_ar
 * @property string|null $description_ar
 * @property string|null $factory_instructions_ar
 * @property string|null $internal_notes
 * @property Carbon|null $planned_start_date
 * @property Carbon|null $planned_end_date
 */
class TransformationPlanStage extends Model
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
            'position' => 'integer',
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
     * The items placed in the stage, in order.
     *
     * @return HasMany<TransformationPlanStageItem, $this>
     */
    public function stageItems(): HasMany
    {
        return $this->hasMany(TransformationPlanStageItem::class)->orderBy('position');
    }
}
