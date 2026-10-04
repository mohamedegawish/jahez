<?php

namespace Database\Factories;

use App\Enums\ProviderApprovalStatus;
use App\Enums\ServiceListingStatus;
use App\Models\CatalogService;
use App\Models\Sector;
use App\Models\ServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic service providers for tests and local demo data only.
 *
 * @extends Factory<ServiceProvider>
 */
class ServiceProviderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Solutions',
        ];
    }

    /**
     * A provider an IMC administrator has approved, so factories can see it.
     */
    public function approved(): static
    {
        return $this->withApprovalStatus(ProviderApprovalStatus::Approved);
    }

    /**
     * A provider in the given approval status.
     */
    public function withApprovalStatus(ProviderApprovalStatus $status): static
    {
        return $this->afterMaking(function (ServiceProvider $serviceProvider) use ($status): void {
            $serviceProvider->approval_status = $status;
            $serviceProvider->approval_changed_at = $status === ProviderApprovalStatus::Pending ? null : now();
        });
    }

    /**
     * Attach the given seeded catalog services (by code) after creation, as listings IMC
     * has approved (ADR-021).
     */
    public function offering(string ...$serviceCodes): static
    {
        return $this->listing(ServiceListingStatus::Approved, ...$serviceCodes);
    }

    /**
     * Attach the given seeded catalog services (by code) after creation, as listings in
     * the given review status.
     */
    public function listing(ServiceListingStatus $status, string ...$serviceCodes): static
    {
        return $this->afterCreating(function (ServiceProvider $serviceProvider) use ($status, $serviceCodes): void {
            $serviceProvider->services()->syncWithoutDetaching(
                CatalogService::query()->whereIn('code', $serviceCodes)->pluck('id')->mapWithKeys(fn (int $id): array => [$id => [
                    'status' => $status->value,
                    'status_changed_at' => $status === ServiceListingStatus::Pending ? null : now(),
                ]])->all(),
            );
        });
    }

    /**
     * Attach the given seeded sectors (by code) after creation.
     */
    public function inSectors(string ...$sectorCodes): static
    {
        return $this->afterCreating(function (ServiceProvider $serviceProvider) use ($sectorCodes): void {
            $serviceProvider->sectors()->sync(Sector::query()->whereIn('code', $sectorCodes)->pluck('id'));
        });
    }
}
