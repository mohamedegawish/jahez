<?php

namespace App\Models;

use App\Enums\ProfileChangeRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A factory member's request to change recorded legal information (ADR-020): the legal
 * name, the registration numbers and the registration documents. Mirrors
 * ProviderProfileChangeRequest (ADR-019): `changes` holds the requested text values,
 * documents are attached as pending_review, and nothing changes on the factory until an
 * IMC administrator approves.
 *
 * @property int $id
 * @property int $factory_id
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
class FactoryProfileChangeRequest extends Model
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
            'factory_id' => 'integer',
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
     * @return BelongsTo<Factory, $this>
     */
    public function industrialFactory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
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
        return $this->hasMany(OrganizationDocument::class, 'factory_profile_change_request_id');
    }
}
