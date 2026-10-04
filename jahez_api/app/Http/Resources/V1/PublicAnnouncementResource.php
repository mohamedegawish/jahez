<?php

namespace App\Http\Resources\V1;

use App\Models\PublicAnnouncement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A landing-page announcement (ADR-022). Visitors get the public fields only; IMC
 * administrators (the /announcements routes) also get the state, schedule, order and
 * authorship. Cover paths are relative to /api/v1; the version query changes with the
 * record, so a replaced image is fetched again.
 *
 * @mixin PublicAnnouncement
 */
class PublicAnnouncementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $admin = ! $request->routeIs('api.v1.public.*');
        $version = $this->updated_at?->getTimestamp() ?? 0;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'badge_text' => $this->badge_text,
            'color' => $this->color,
            'link_path' => $this->link_path,
            'tags' => $this->tags ?? [],
            'countdown_text' => $this->countdown_text,
            'cover_alt' => $this->cover_alt,
            'cover_path' => ! $this->hasCover() ? null : ($admin
                ? "/announcements/{$this->id}/cover?v={$version}"
                : "/public/announcements/{$this->id}/cover?v={$version}"),
            'ends_at' => $this->ends_at?->toIso8601ZuluString(),
            ...($admin ? [
                'state' => $this->state(),
                'sort_order' => $this->sort_order,
                'starts_at' => $this->starts_at?->toIso8601ZuluString(),
                'published_at' => $this->published_at?->toIso8601ZuluString(),
                'created_at' => $this->created_at?->toIso8601ZuluString(),
                'updated_at' => $this->updated_at?->toIso8601ZuluString(),
            ] : []),
        ];
    }
}
