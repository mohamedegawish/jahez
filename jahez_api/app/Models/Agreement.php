<?php

namespace App\Models;

use App\Billing\PolicyCalendar;
use App\Billing\PolicyContext;
use App\Billing\PolicyNotConfiguredException;
use App\Billing\PolicyResolver;
use App\Enums\AgreementReviewStatus;
use App\Enums\ContractStatus;
use App\Enums\FinancialPolicyKind;
use App\Enums\PolicyBasis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * What a factory and a provider agreed: the provider's offer version the factory
 * accepted (ADR-017). Created in the same transaction as the acceptance; never changed.
 * It is not a contract, an invoice or a payment.
 *
 * @property int $id
 * @property int $provider_request_id
 * @property int $service_request_id
 * @property int $factory_id
 * @property int $service_provider_id
 * @property int $catalog_service_id
 * @property int $offer_id
 * @property string $price_amount
 * @property string $currency
 * @property int|null $concluded_by_user_id
 * @property PolicyBasis $policy_basis
 * @property int|null $revenue_share_policy_version_id
 * @property Carbon $concluded_at
 */
class Agreement extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_request_id' => 'integer',
            'service_request_id' => 'integer',
            'factory_id' => 'integer',
            'service_provider_id' => 'integer',
            'catalog_service_id' => 'integer',
            'offer_id' => 'integer',
            'price_amount' => 'decimal:2',
            'concluded_by_user_id' => 'integer',
            'concluded_at' => 'datetime',
            'policy_basis' => PolicyBasis::class,
            'revenue_share_policy_version_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Agreements are never changed.'));
        static::deleting(fn (): never => throw new LogicException('Agreements are never changed.'));
    }

    /**
     * Record the agreement for an offer the factory just accepted. Call inside the
     * acceptance transaction.
     */
    public static function conclude(ProviderRequest $thread, ServiceRequest $serviceRequest, Offer $offer, User $actor): self
    {
        $agreement = new self;
        $agreement->provider_request_id = $thread->id;
        $agreement->service_request_id = $serviceRequest->id;
        $agreement->factory_id = $serviceRequest->factory_id;
        $agreement->service_provider_id = $thread->service_provider_id;
        $agreement->catalog_service_id = $serviceRequest->catalog_service_id;
        $agreement->offer_id = $offer->id;
        $agreement->price_amount = $offer->price_amount;
        $agreement->currency = $offer->currency;
        $agreement->concluded_by_user_id = $actor->id;
        $agreement->concluded_at = now();
        $agreement->policy_basis = PolicyBasis::Policy;
        $agreement->revenue_share_policy_version_id = self::revenueSharePolicyOn($agreement)?->id;
        $agreement->save();

        return $agreement;
    }

    /**
     * The revenue-share policy version in effect for the agreement when it is concluded
     * (ADR-023), recorded as part of what was agreed. Accepting an offer is never blocked
     * by it: without an applicable approved policy, or with two that conflict, nothing is
     * recorded and invoices resolve the policy again when they are issued.
     */
    private static function revenueSharePolicyOn(self $agreement): ?FinancialPolicyVersion
    {
        try {
            return PolicyResolver::resolve(FinancialPolicyKind::RevenueShare, PolicyContext::forAgreement($agreement), PolicyCalendar::today(), lock: true);
        } catch (PolicyNotConfiguredException) {
            return null;
        }
    }

    /**
     * The side of the agreement the user is on, or null for anyone else.
     */
    public function sideOf(User $user): ?string
    {
        return match (true) {
            $user->belongsToFactory($this->factory_id) => ProviderRequestMessage::SIDE_FACTORY,
            $user->belongsToServiceProvider($this->service_provider_id) => ProviderRequestMessage::SIDE_PROVIDER,
            default => null,
        };
    }

    /**
     * @return BelongsTo<ProviderRequest, $this>
     */
    public function providerRequest(): BelongsTo
    {
        return $this->belongsTo(ProviderRequest::class);
    }

    /**
     * @return BelongsTo<ServiceRequest, $this>
     */
    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    /**
     * @return BelongsTo<Factory, $this>
     */
    public function industrialFactory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }

    /**
     * @return BelongsTo<ServiceProvider, $this>
     */
    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class);
    }

    /**
     * @return BelongsTo<CatalogService, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(CatalogService::class, 'catalog_service_id');
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return BelongsTo<FinancialPolicyVersion, $this>
     */
    public function revenueSharePolicyVersion(): BelongsTo
    {
        return $this->belongsTo(FinancialPolicyVersion::class, 'revenue_share_policy_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function concludedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concluded_by_user_id');
    }

    /**
     * IMC's decision on the agreement, once taken (ADR-020).
     *
     * @return HasOne<AgreementReview, $this>
     */
    public function review(): HasOne
    {
        return $this->hasOne(AgreementReview::class);
    }

    public function reviewStatus(): AgreementReviewStatus
    {
        return $this->review->decision ?? AgreementReviewStatus::Pending;
    }

    /**
     * Refuses with 409 a step that needs IMC's approval of the agreement (a contract
     * draft, an invoice) while it is awaiting review or was rejected (ADR-020). The
     * owner's Phase 2 brief requires the ministry approval; the setting exists so the
     * rule can be reviewed with OQ-17 without a code change.
     */
    public function ensureApprovedByImc(): void
    {
        if (! (bool) config('jahez.agreements.imc_approval_required', true)) {
            return;
        }

        $status = $this->review()->first()->decision ?? AgreementReviewStatus::Pending;

        if ($status !== AgreementReviewStatus::Approved) {
            throw new ConflictHttpException($status === AgreementReviewStatus::Pending
                ? 'This agreement is awaiting IMC review; a contract draft or an invoice needs IMC\'s approval first.'
                : 'IMC rejected this agreement, so no contract draft or invoice can be made for it.');
        }
    }

    /**
     * Every contract draft, oldest version first.
     *
     * @return HasMany<Contract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class)->orderBy('version');
    }

    /**
     * The draft in force, if any.
     *
     * @return HasOne<Contract, $this>
     */
    public function activeContract(): HasOne
    {
        return $this->hasOne(Contract::class)->where('status', ContractStatus::Draft);
    }
}
