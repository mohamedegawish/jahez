<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Factory as IndustrialFactory;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * By default a user is a member of a new factory: the least-privileged role.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => Role::FactoryMember,
            'factory_id' => IndustrialFactory::factory(),
            'service_provider_id' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * An IMC administrator, with no organization link.
     */
    public function imcAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::ImcAdmin,
            'factory_id' => null,
            'service_provider_id' => null,
        ]);
    }

    /**
     * A member of the given factory, or of a new one.
     */
    public function factoryMember(?IndustrialFactory $factory = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::FactoryMember,
            'factory_id' => $factory ?? IndustrialFactory::factory(),
            'service_provider_id' => null,
        ]);
    }

    /**
     * A member of the given service provider, or of a new one.
     */
    public function providerMember(?ServiceProvider $serviceProvider = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::ProviderMember,
            'factory_id' => null,
            'service_provider_id' => $serviceProvider ?? ServiceProvider::factory(),
        ]);
    }

    /**
     * A deactivated account.
     */
    public function deactivated(): static
    {
        return $this->state(fn (array $attributes) => [
            'deactivated_at' => now(),
        ]);
    }
}
