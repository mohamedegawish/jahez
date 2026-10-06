<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A finish-to-start dependency between two placements of one plan version (ADR-025):
 * `stage_item_id` may start only once `depends_on_stage_item_id`'s item is completed.
 *
 * @property int $id
 * @property int $transformation_plan_version_id
 * @property int $stage_item_id
 * @property int $depends_on_stage_item_id
 */
class TransformationPlanDependency extends Model
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
            'stage_item_id' => 'integer',
            'depends_on_stage_item_id' => 'integer',
        ];
    }
}
