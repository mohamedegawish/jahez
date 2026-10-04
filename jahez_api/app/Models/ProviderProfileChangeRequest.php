<?php

namespace App\Models;

use App\Enums\ProfileChangeRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A provider member's request to change legal information IMC has verified (ADR-019):
 * the legal name, the registration numbers and the registration documents. `changes`
 * holds the requested text values; requested documents are attached with the status
 * pending_review. Nothing on the provider changes until an IMC administrator approves.
 *
 * @property int $id
 * @property int $service_provider_id
 * @property int $requested_by_user_id
 * @property ProfileChangeRequestStatus $status
 * @property bool|null $is_open
 * @property array<string, string|null>|null $changes
 * @property string|null $note
 * @property int|null $reviewed_by_user_id
 * @property Carbon|null $reviewed_at
 * @property string|null $review_reason
 * @property Carbon|null $created_at
 */
class ProviderProfileChangeRequest extends Model
{
    /**
     * Mirrors the column defaults of a new request.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'is_open' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service_provider_id' => 'integer',
            'requested_by_user_id' => 'integer',
            'status' => ProfileChangeRequestStatus::class,
            'is_open' => 'boolean',
            'changes' => 'array',
            'reviewed_by_user_id' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Close the request with a final status. The caller saves it.
     */
    public function close(ProfileChangeRequestStatus $status, ?User $reviewer = null, ?string $reason = null): void
    {
        $this->status = $status;
        $this->is_open = null;
        $this->reviewed_by_user_id = $reviewer?->id;
        $this->reviewed_at = $reviewer !== null ? now() : null;
        $this->review_reason = $reason;
    }

    /**
     * @return BelongsTo<ServiceProvider, $this>
     */
    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * The documents submitted with the request.
     *
     * @return HasMany<OrganizationDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(OrganizationDocument::class, 'provider_profile_change_request_id');
    }
}
