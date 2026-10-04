<?php

namespace Database\Factories;

use App\Enums\FactoryApprovalStatus;
use App\Models\Factory;
use App\Models\Sector;
use Illuminate\Database\Eloquent\Factories\Factory as ModelFactory;

/**
 * Synthetic factories for tests and local demo data only. A factory is approved unless a
 * test asks for another status (ADR-021), so tests of other modules are not tied to the
 * review step.
 *
 * @extends ModelFactory<Factory>
 */
class FactoryFactory extends ModelFactory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Factory',
        ];
    }

    /**
     * Approved by default; withApprovalStatus() runs after this and overrides it.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Factory $factory): void {
            if ($factory->approval_changed_at === null && $factory->approval_status === FactoryApprovalStatus::Pending) {
                $factory->approval_status = FactoryApprovalStatus::Approved;
                $factory->approval_changed_at = now();
            }
        });
    }

    /**
     * A factory in the given approval status.
     */
    public function withApprovalStatus(FactoryApprovalStatus $status): static
    {
        return $this->afterMaking(function (Factory $factory) use ($status): void {
            $factory->approval_status = $status;
            $factory->approval_changed_at = $status === FactoryApprovalStatus::Pending ? null : now();
        });
    }

    /**
     * Attach the given seeded sectors (by code) after creation.
     */
    public function inSectors(string ...$sectorCodes): static
    {
        return $this->afterCreating(function (Factory $factory) use ($sectorCodes): void {
            $factory->sectors()->sync(Sector::query()->whereIn('code', $sectorCodes)->pluck('id'));
        });
    }
}
