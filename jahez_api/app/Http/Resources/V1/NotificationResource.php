<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * An in-app notification (ADR-020): what happened, a link to the record in the web
 * client, and whether it was read.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->data;

        return [
            'id' => $this->id,
            'event' => $data['event'] ?? null,
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'link' => $data['link'] ?? null,
            'subject' => $data['subject'] ?? null,
            'read_at' => $this->read_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
