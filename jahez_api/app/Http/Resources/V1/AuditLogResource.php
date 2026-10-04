<?php

namespace App\Http\Resources\V1;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
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
            'event' => $this->event->value,
            'actor' => $this->actor_user_id === null ? null : [
                'id' => $this->actor_user_id,
                'name' => $this->actor?->name,
                'email' => $this->actor?->email,
            ],
            'subject' => $this->subject_type === null ? null : [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
            ],
            'ip_address' => $this->ip_address,
            'request_id' => $this->request_id,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
