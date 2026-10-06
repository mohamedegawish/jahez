<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\FactoryApprovalStatus;
use Database\Factories\FactoryFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * An industrial factory (مصنع) taking part in the programme. Its profile is the name,
 * the declared company size (OQ-04, from a configurable list), its sectors, and the
 * registration details the owner added on 2026-10-03 (ADR-019): legal name, contact
 * person, address, registration numbers and documents. None of the added fields is
 * mandatory (OQ-19).
 *
 * @property int $id
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $size
 * @property string|null $contact_name
 * @property string|null $contact_job_title
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $website
 * @property string|null $governorate
 * @property string|null $city
 * @property string|null $address
 * @property string|null $commercial_registration_number
 * @property string|null $tax_registration_number
 * @property FactoryApprovalStatus $approval_status
 * @property string|null $approval_reason
 * @property Carbon|null $approval_changed_at
 */
class Factory extends Model
{
    /** @use HasFactory<FactoryFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable. Approval fields are deliberately excluded:
     * they change only through the IMC approval action (ADR-021).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'size',
        ...self::PROFILE_FIELDS,
    ];

    /**
     * Mirrors the column default so a model that was just created (and not reloaded)
     * can answer approval questions without reading a missing attribute.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'approval_status' => 'pending',
        'approval_reason' => null,
        'approval_changed_at' => null,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approval_status' => FactoryApprovalStatus::class,
            'approval_changed_at' => 'datetime',
        ];
    }

    public function isApproved(): bool
    {
        return $this->approval_status === FactoryApprovalStatus::Approved;
    }

    /**
     * Whether the factory may send service requests now: it is approved, or the approval
     * gate is off (config jahez.factories.approval_required, PROPOSED OQ-46).
     */
    public function maySendServiceRequests(): bool
    {
        return $this->isApproved() || ! (bool) config('jahez.factories.approval_required', true);
    }

    /**
     * The registration details a factory member edits (ADR-019). The name, sectors and
     * size are handled separately: members do not set the size (owner decision).
     */
    public const PROFILE_FIELDS = [
        'legal_name',
        'contact_name',
        'contact_job_title',
        'contact_email',
        'contact_phone',
        'website',
        'governorate',
        'city',
        'address',
        'commercial_registration_number',
        'tax_registration_number',
    ];

    /**
     * Legal information that, once recorded, a factory member changes only through a
     * change request IMC reviews (ADR-020; config jahez.factories.legal_changes_reviewed).
     */
    public const LEGAL_FIELDS = [
        'legal_name',
        'commercial_registration_number',
        'tax_registration_number',
    ];

    /**
     * Whether the field holds a recorded value that members may no longer replace
     * directly: the review rule is on and the stored value is not empty.
     */
    public function legalValueIsRecorded(string $field): bool
    {
        $value = $this->getAttribute($field);

        return (bool) config('jahez.factories.legal_changes_reviewed', true) && $value !== null && $value !== '';
    }

    /**
     * Whether an active legal document of the type exists that members may no longer
     * replace directly.
     */
    public function legalDocumentIsRecorded(DocumentType $type): bool
    {
        return (bool) config('jahez.factories.legal_changes_reviewed', true)
            && $this->activeDocuments()->where('type', $type)->exists();
    }

    /**
     * @return HasMany<FactoryProfileChangeRequest, $this>
     */
    public function changeRequests(): HasMany
    {
        return $this->hasMany(FactoryProfileChangeRequest::class);
    }

    /**
     * The change request IMC has not decided yet, if any (at most one).
     *
     * @return HasOne<FactoryProfileChangeRequest, $this>
     */
    public function openChangeRequest(): HasOne
    {
        return $this->hasOne(FactoryProfileChangeRequest::class)->where('is_open', true);
    }

    /**
     * @return BelongsToMany<Sector, $this>
     */
    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(Sector::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Every file the factory uploaded, including superseded ones.
     *
     * @return HasMany<OrganizationDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(OrganizationDocument::class);
    }

    /**
     * The current logo and registration documents.
     *
     * @return HasMany<OrganizationDocument, $this>
     */
    public function activeDocuments(): HasMany
    {
        return $this->documents()->where('status', DocumentStatus::Active);
    }

    /**
     * The configured onboarding fields (OQ-19) the factory has not filled, in
     * configuration order. Unknown names in the configuration are ignored.
     *
     * @return list<string>
     */
    public function missingProfileFields(): array
    {
        $allowed = [...self::PROFILE_FIELDS, 'sectors'];
        $required = array_intersect((array) config('jahez.factories.required_profile_fields', []), $allowed);

        return array_values(array_filter($required, fn (string $field): bool => match ($field) {
            'sectors' => $this->relationLoaded('sectors') ? $this->sectors->isEmpty() : ! $this->sectors()->exists(),
            default => $this->getAttribute($field) === null || $this->getAttribute($field) === '',
        }));
    }

    /**
     * The factory's legacy manual IMC classifications (ADR-016, superseded by ADR-018).
     * Kept read-only as history; they never set the current classification.
     *
     * @return HasMany<FactoryAssessment, $this>
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(FactoryAssessment::class);
    }

    /**
     * The service requests the factory has sent (ADR-015).
     *
     * @return HasMany<ServiceRequest, $this>
     */
    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    /**
     * The factory's completed digital readiness assessments (ADR-018).
     *
     * @return HasMany<ReadinessAssessment, $this>
     */
    public function readinessAssessments(): HasMany
    {
        return $this->hasMany(ReadinessAssessment::class);
    }

    /**
     * The current readiness classification: the latest completed assessment, and on the
     * same completion time the latest recorded (ADR-018). The server sets completed_at.
     *
     * @return HasOne<ReadinessAssessment, $this>
     */
    public function currentReadinessAssessment(): HasOne
    {
        return $this->hasOne(ReadinessAssessment::class)->ofMany(['completed_at' => 'max', 'id' => 'max']);
    }

    /**
     * The readiness levels the factory opened by completing its plan's services of the
     * level below (ADR-026). Its level is the highest of these and its current assessment.
     *
     * @return HasMany<ReadinessLevelUnlock, $this>
     */
    public function readinessLevelUnlocks(): HasMany
    {
        return $this->hasMany(ReadinessLevelUnlock::class);
    }

    /**
     * The catalog services the roadmap recommends for the factory's current readiness
     * category; none before its first assessment. A recommendation says which services
     * may be relevant, never which providers are eligible.
     *
     * @return Builder<CatalogService>
     */
    public function recommendedCatalogServices(): Builder
    {
        $categoryId = $this->currentReadinessAssessment()->first()?->readiness_category_id;

        return CatalogService::query()->whereHas(
            'readinessRecommendations',
            fn (Builder $recommendations) => $recommendations->where('readiness_category_id', $categoryId),
        );
    }

    /**
     * Factories whose name contains the term, matched literally: LIKE wildcards in the
     * term are escaped.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function nameContains(Builder $query, string $term): void
    {
        $query->whereLike('name', '%'.addcslashes($term, '%_\\').'%');
    }
}
