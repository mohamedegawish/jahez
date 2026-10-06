<?php

namespace App\Models;

use App\Enums\TransformationPlanStatus;
use App\Enums\TransformationPlanVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A factory's digital transformation plan, written by IMC (ADR-025). The content lives in
 * versions; the items (one per catalog service) carry the execution state across versions.
 * Status, ownership and the readiness basis are set by the server, never mass-assigned.
 *
 * @property int $id
 * @property int $factory_id
 * @property TransformationPlanStatus $status
 * @property bool|null $is_open
 * @property string|null $status_reason
 * @property Carbon|null $status_changed_at
 * @property int|null $based_on_readiness_assessment_id
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Factory $industrialFactory
 */
class TransformationPlan extends Model
{
    /**
     * Mirrors the column defaults so a model that was just created can be read without
     * reloading it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'is_open' => true,
        'status_reason' => null,
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
            'factory_id' => 'integer',
            'status' => TransformationPlanStatus::class,
            'is_open' => 'boolean',
            'status_changed_at' => 'datetime',
            'based_on_readiness_assessment_id' => 'integer',
            'created_by_user_id' => 'integer',
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
     * @return BelongsTo<ReadinessAssessment, $this>
     */
    public function basedOnAssessment(): BelongsTo
    {
        return $this->belongsTo(ReadinessAssessment::class, 'based_on_readiness_assessment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Every version, oldest first.
     *
     * @return HasMany<TransformationPlanVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(TransformationPlanVersion::class)->orderBy('version');
    }

    /**
     * The version the factory sees, if one was published.
     *
     * @return HasOne<TransformationPlanVersion, $this>
     */
    public function publishedVersion(): HasOne
    {
        return $this->hasOne(TransformationPlanVersion::class)->where('status', TransformationPlanVersionStatus::Published);
    }

    /**
     * The version being edited, if any.
     *
     * @return HasOne<TransformationPlanVersion, $this>
     */
    public function draftVersion(): HasOne
    {
        return $this->hasOne(TransformationPlanVersion::class)->where('status', TransformationPlanVersionStatus::Draft);
    }

    /**
     * @return HasMany<TransformationPlanItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TransformationPlanItem::class)->orderBy('id');
    }

    /**
     * Apply a status change the workflow allows, or refuse it with 409.
     */
    public function moveTo(TransformationPlanStatus $next, ?string $reason = null): void
    {
        if (! $this->status->canBecome($next)) {
            throw new ConflictHttpException("This transformation plan is {$this->status->value} and cannot become {$next->value}.");
        }

        $this->status = $next;
        $this->is_open = $next === TransformationPlanStatus::Closed ? null : true;
        $this->status_reason = $reason;
        $this->status_changed_at = now();
        $this->save();
    }
}
