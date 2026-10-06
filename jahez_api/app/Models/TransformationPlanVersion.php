<?php

namespace App\Models;

use App\Enums\TransformationPlanVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One version of a transformation plan (ADR-025): its stages, the placement of the plan's
 * items in them, and the dependencies between those placements. Only a draft changes;
 * a published or superseded version is history.
 *
 * @property int $id
 * @property int $transformation_plan_id
 * @property int $version
 * @property TransformationPlanVersionStatus $status
 * @property bool|null $is_draft
 * @property bool|null $is_published
 * @property int $revision
 * @property string $title
 * @property string|null $summary_ar
 * @property string|null $change_note
 * @property int|null $based_on_version_id
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property int|null $published_by_user_id
 * @property Carbon|null $published_at
 * @property Carbon|null $superseded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TransformationPlanVersion extends Model
{
    /**
     * Mirrors the column defaults so a model that was just created can be read without
     * reloading it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'is_draft' => true,
        'is_published' => null,
        'revision' => 0,
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
            'version' => 'integer',
            'status' => TransformationPlanVersionStatus::class,
            'is_draft' => 'boolean',
            'is_published' => 'boolean',
            'revision' => 'integer',
            'based_on_version_id' => 'integer',
            'created_by_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
            'published_by_user_id' => 'integer',
            'published_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === TransformationPlanVersionStatus::Draft;
    }

    /**
     * @return BelongsTo<TransformationPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(TransformationPlan::class, 'transformation_plan_id');
    }

    /**
     * Stages in the order IMC chose.
     *
     * @return HasMany<TransformationPlanStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(TransformationPlanStage::class)->orderBy('position');
    }

    /**
     * @return HasMany<TransformationPlanStageItem, $this>
     */
    public function stageItems(): HasMany
    {
        return $this->hasMany(TransformationPlanStageItem::class);
    }

    /**
     * @return HasMany<TransformationPlanDependency, $this>
     */
    public function dependencies(): HasMany
    {
        return $this->hasMany(TransformationPlanDependency::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
