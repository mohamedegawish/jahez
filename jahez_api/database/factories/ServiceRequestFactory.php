<?php

namespace Database\Factories;

use App\Models\CatalogService;
use App\Models\Factory as IndustrialFactory;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic service requests for tests only. The catalog must be seeded first
 * (ReferenceDataSeeder): the request uses the first catalog service unless one is given.
 *
 * @extends Factory<ServiceRequest>
 */
class ServiceRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'factory_id' => IndustrialFactory::factory(),
            'catalog_service_id' => fn (): mixed => CatalogService::query()->orderBy('id')->value('id'),
            'title' => fake()->sentence(4),
            'need' => fake()->paragraph(),
            'requirements' => null,
            'created_by_user_id' => fn (array $attributes): int => User::factory()
                ->factoryMember(IndustrialFactory::query()->whereKey($attributes['factory_id'])->firstOrFail())
                ->create()
                ->id,
        ];
    }

    /**
     * A request for the seeded catalog service with the given code.
     */
    public function forService(string $serviceCode): static
    {
        return $this->state(fn (): array => [
            'catalog_service_id' => CatalogService::query()->where('code', $serviceCode)->value('id'),
        ]);
    }
}
