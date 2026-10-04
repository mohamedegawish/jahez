<?php

namespace App\Http\Resources\V1;

use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One offer version (ADR-015). `state` is "current" for the latest version while the
 * negotiation is open, "expired" for the latest version once its `valid_until` date has
 * passed, "lapsed" for the latest version once the thread has ended without an
 * agreement, "superseded" for older versions and "accepted" for the version the factory
 * accepted. The price is a
 * decimal string in EGP and is informational: it is not an invoice (OQ-15, OQ-16).
 * Expects the author to be loaded.
 *
 * @mixin Offer
 */
class OfferResource extends JsonResource
{
    public const STATE_CURRENT = 'current';

    public const STATE_SUPERSEDED = 'superseded';

    public const STATE_ACCEPTED = 'accepted';

    public const STATE_LAPSED = 'lapsed';

    public const STATE_EXPIRED = 'expired';

    public function __construct(Offer $offer, private readonly string $state = self::STATE_CURRENT)
    {
        parent::__construct($offer);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'state' => $this->state,
            'scope' => $this->scope,
            'deliverables' => $this->deliverables,
            'duration_days' => $this->duration_days,
            'valid_until' => $this->valid_until?->toDateString(),
            'price' => ['amount' => $this->price_amount, 'currency' => $this->currency],
            'author' => $this->author === null ? null : ['id' => $this->author->id, 'name' => $this->author->name],
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
