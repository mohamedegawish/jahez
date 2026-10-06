<?php

namespace App\Models;

use App\Enums\ReadinessCategoryCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A readiness level a factory opened by completing its transformation plan's services of
 * the level below (ADR-026). Written only by App\Readiness\LevelProgression; never
 * updated or deleted, so an opened level stays open.
 *
 * @property int $id
 * @property int $factory_id
 * @property ReadinessCategoryCode $level
 * @property ReadinessCategoryCode $from_level
 * @property int $transformation_plan_id
 * @property int $unlocked_by_user_id
 * @property Carbon $unlocked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReadinessLevelUnlock extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'factory_id' => 'integer',
            'level' => ReadinessCategoryCode::class,
            'from_level' => ReadinessCategoryCode::class,
            'transformation_plan_id' => 'integer',
            'unlocked_by_user_id' => 'integer',
            'unlocked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Readiness level unlocks are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Readiness level unlocks are append-only.'));
    }

    /**
     * Named like User::industrialFactory(): factory() is reserved for model factories.
     *
     * @return BelongsTo<Factory, $this>
     */
    public function industrialFactory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }

    /**
     * @return BelongsTo<TransformationPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(TransformationPlan::class, 'transformation_plan_id');
    }
}
