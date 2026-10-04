<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exposes no credentials, tokens or internal flags. The organization relations
 * (industrialFactory, serviceProvider) must be eager loaded by the caller.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'organization' => $this->organization(),
            'is_active' => $this->isActive(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array{type: string, id: int, name: string}|null
     */
    private function organization(): ?array
    {
        if ($this->factory_id !== null && $this->industrialFactory !== null) {
            return ['type' => 'factory', 'id' => $this->industrialFactory->id, 'name' => $this->industrialFactory->name];
        }

        if ($this->service_provider_id !== null && $this->serviceProvider !== null) {
            return ['type' => 'service_provider', 'id' => $this->serviceProvider->id, 'name' => $this->serviceProvider->name];
        }

        return null;
    }
}
