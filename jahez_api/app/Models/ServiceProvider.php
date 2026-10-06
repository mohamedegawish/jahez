<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\ProviderApprovalStatus;
use App\Enums\ServiceListingStatus;
use Database\Factories\ServiceProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A digital-transformation service provider (مقدم الخدمة) organization. This is a domain
 * model, not a Laravel service provider (those live in App\Providers). The profile fields
 * are those of the services workbook (ADR-014); approval is set only through the IMC
 * approval action, never by mass assignment.
 *
 * @property int $id
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $description
 * @property string|null $representative_name
 * @property string|null $job_title
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $website
 * @property int|null $dx_experience_years
 * @property string|null $governorate
 * @property string|null $city
 * @property string|null $address
 * @property string|null $commercial_registration_number
 * @property string|null $tax_registration_number
 * @property ProviderApprovalStatus $approval_status
 * @property string|null $approval_reason
 * @property Carbon|null $approval_changed_at
 */
class ServiceProvider extends Model
{
    /** @use HasFactory<ServiceProviderFactory> */
    use HasFactory;

    /**
     * The ordinary profile fields: the workbook fields of the provider registration form
     * (ADR-014) and the description and address the owner added (ADR-019). Members edit
     * them freely; an edit never changes the approval status.
     */
    public const PROFILE_FIELDS = [
        'name',
        'description',
        'representative_name',
        'job_title',
        'email',
        'phone',
        'website',
        'dx_experience_years',
        'governorate',
        'city',
        'address',
    ];

    /**
     * Legal information IMC verifies when it approves a provider (ADR-019). Once the
     * provider has been approved, its members change these only through a change
     * request an IMC administrator reviews; before that, the whole profile is under
     * review anyway and they are edited directly.
     */
    public const LEGAL_FIELDS = [
        'legal_name',
        'commercial_registration_number',
        'tax_registration_number',
    ];

    /**
     * Profile fields the owner may make mandatory before approval (OQ-36), as named in
     * config jahez.providers.required_profile_fields.
     */
    public const REQUIRABLE_FIELDS = [
        'legal_name',
        'description',
        'representative_name',
        'job_title',
        'email',
        'phone',
        'website',
        'dx_experience_years',
        'governorate',
        'city',
        'address',
        'commercial_registration_number',
        'tax_registration_number',
        'sectors',
        'services',
    ];

    /**
     * The attributes that are mass assignable. Approval fields are deliberately excluded;
     * who may change the legal fields is decided by the profile request.
     *
     * @var list<string>
     */
    protected $fillable = [...self::PROFILE_FIELDS, ...self::LEGAL_FIELDS];

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
            'dx_experience_years' => 'integer',
            'approval_status' => ProviderApprovalStatus::class,
            'approval_changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Sector, $this>
     */
    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(Sector::class)->orderBy('sort_order');
    }

    /**
     * Every catalog service the provider lists, whatever IMC decided about the listing
     * (ADR-021). Pivot: status, status_reason, status_changed_at, submitted_at.
     *
     * @return BelongsToMany<CatalogService, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(CatalogService::class)
            ->withPivot(['status', 'status_reason', 'status_changed_at', 'submitted_at'])
            ->orderBy('service_category_id')
            ->orderBy('sort_order');
    }

    /**
     * The packages and prices of every listing of the provider (ADR-027), in display
     * order; grouped by catalog service where shown.
     *
     * @return HasMany<ServiceListingPackage, $this>
     */
    public function listingPackages(): HasMany
    {
        return $this->hasMany(ServiceListingPackage::class)->orderBy('catalog_service_id')->orderBy('position');
    }

    /**
     * The listings IMC approved: the only services factories see the provider offering.
     *
     * @return BelongsToMany<CatalogService, $this>
     */
    public function approvedServices(): BelongsToMany
    {
        return $this->services()->wherePivot('status', ServiceListingStatus::Approved->value);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * IMC evaluations against the DOC §6 criteria, oldest first.
     *
     * @return HasMany<ProviderEvaluation, $this>
     */
    public function evaluations(): HasMany
    {
        return $this->hasMany(ProviderEvaluation::class);
    }

    /**
     * Every file the provider uploaded, including superseded and pending ones.
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
     * @return HasMany<ProviderProfileChangeRequest, $this>
     */
    public function changeRequests(): HasMany
    {
        return $this->hasMany(ProviderProfileChangeRequest::class);
    }

    /**
     * The change request IMC has not decided yet, if any (at most one).
     *
     * @return HasOne<ProviderProfileChangeRequest, $this>
     */
    public function openChangeRequest(): HasOne
    {
        return $this->hasOne(ProviderProfileChangeRequest::class)->where('is_open', true);
    }

    /**
     * Whether IMC has verified the provider's legal information: the provider was
     * approved, and may since have been suspended. A pending or rejected provider has
     * never been approved (a rejection is decided only from pending).
     */
    public function hasVerifiedLegalInformation(): bool
    {
        return in_array($this->approval_status, [ProviderApprovalStatus::Approved, ProviderApprovalStatus::Suspended], true);
    }

    /**
     * The configured required profile fields (OQ-36) that this provider has not filled,
     * in configuration order. Unknown names in the configuration are ignored here and
     * reported by app:check-production.
     *
     * @return list<string>
     */
    public function missingRequiredProfileFields(): array
    {
        $required = array_intersect((array) config('jahez.providers.required_profile_fields', []), self::REQUIRABLE_FIELDS);

        return array_values(array_filter($required, fn (string $field): bool => match ($field) {
            'sectors' => ! $this->sectors()->exists(),
            'services' => ! $this->services()->exists(),
            default => $this->getAttribute($field) === null || $this->getAttribute($field) === '',
        }));
    }

    public function isApproved(): bool
    {
        return $this->approval_status === ProviderApprovalStatus::Approved;
    }

    /**
     * Providers an IMC administrator has approved: the only ones factories may see.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function approved(Builder $query): void
    {
        $query->where('approval_status', ProviderApprovalStatus::Approved);
    }

    /**
     * Providers eligible for the factory (owner decision 2026-10-03): approved, and
     * targeting at least one of the factory's sectors. The service condition is applied
     * separately, by the caller.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function eligibleFor(Builder $query, Factory $factory): void
    {
        $query->where('approval_status', ProviderApprovalStatus::Approved)
            ->whereHas('sectors', fn (Builder $sectors) => $sectors->whereIn(
                'sectors.id',
                $factory->sectors()->select('sectors.id'),
            ));
    }

    /**
     * Providers whose name contains the term, matched literally: LIKE wildcards in the
     * term are escaped.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function nameContains(Builder $query, string $term): void
    {
        $query->whereLike('name', '%'.addcslashes($term, '%_\\').'%');
    }

    /**
     * Providers that offer the given catalog service through a listing IMC approved
     * (ADR-021). A pending, rejected or suspended listing does not count.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function offering(Builder $query, CatalogService $service): void
    {
        $query->whereHas('approvedServices', fn (Builder $services) => $services->whereKey($service->id));
    }
}
