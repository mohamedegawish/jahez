<?php

namespace App\Http\Resources\V1;

use App\Models\ProviderRequestMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A negotiation message (ADR-015). Expects the author to be loaded.
 *
 * @mixin ProviderRequestMessage
 */
class NegotiationMessageResource extends JsonResource
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
            'author_side' => $this->author_side,
            'author' => $this->author === null ? null : ['id' => $this->author->id, 'name' => $this->author->name],
            'body' => $this->body,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
