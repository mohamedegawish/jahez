<?php

namespace Database\Factories;

use App\Enums\ProviderRequestStatus;
use App\Models\ProviderRequest;
use App\Models\ServiceProvider;
use App\Models\ServiceRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic provider requests for tests only: a service request as sent to one
 * approved provider.
 *
 * @extends Factory<ProviderRequest>
 */
class ProviderRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_request_id' => ServiceRequest::factory(),
            'service_provider_id' => ServiceProvider::factory()->approved(),
        ];
    }

    /**
     * A provider request in the given status.
     */
    public function withStatus(ProviderRequestStatus $status): static
    {
        return $this->afterMaking(function (ProviderRequest $providerRequest) use ($status): void {
            $providerRequest->status = $status;
            $providerRequest->status_changed_at = $status === ProviderRequestStatus::Pending ? null : now();
        });
    }

    /**
     * A provider request the provider accepted, so the negotiation is open.
     */
    public function accepted(): static
    {
        return $this->withStatus(ProviderRequestStatus::Accepted);
    }
}
