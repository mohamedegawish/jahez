<?php

namespace App\Models;

use App\Billing\PolicyCalendar;
use App\Enums\ContractStatus;
use App\Enums\PolicyBasis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A contract draft for an agreement (ADR-017; OQ-17 interim: a draft, not legally
 * binding). It carries the DOC §6 knowledge-transfer commitment. Status changes only
 * through moveTo().
 *
 * @property int $id
 * @property int $agreement_id
 * @property int $version
 * @property ContractStatus $status
 * @property int $knowledge_transfer_trainees
 * @property string $knowledge_transfer_plan
 * @property string|null $notes
 * @property int $drafted_by_user_id
 * @property PolicyBasis $policy_basis
 * @property int|null $contract_template_version_id
 * @property array<string, mixed>|null $terms_snapshot
 * @property string|null $status_reason
 * @property int|null $status_changed_by_user_id
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Contract extends Model
{
    /**
     * DOC §6: the provider trains at least two IMC engineers in the field throughout the
     * execution.
     */
    public const MIN_KNOWLEDGE_TRANSFER_TRAINEES = 2;

    /**
     * Mirrors the column defaults so a model that was just created can be read without
     * reloading it.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'notes' => null,
        'status_reason' => null,
        'status_changed_by_user_id' => null,
        'status_changed_at' => null,
        'policy_basis' => 'policy',
        'contract_template_version_id' => null,
        'terms_snapshot' => null,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'agreement_id' => 'integer',
            'version' => 'integer',
            'status' => ContractStatus::class,
            'knowledge_transfer_trainees' => 'integer',
            'drafted_by_user_id' => 'integer',
            'status_changed_by_user_id' => 'integer',
            'status_changed_at' => 'datetime',
            'policy_basis' => PolicyBasis::class,
            'contract_template_version_id' => 'integer',
            'terms_snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Agreement, $this>
     */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function draftedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'drafted_by_user_id');
    }

    /**
     * What the draft is generated from, frozen when it is drafted (ADR-023): the agreed
     * offer and price, the parties, the policy versions that applied and the contract
     * template version with its clauses. Editing a template or a policy later never
     * changes a draft already made. The snapshot always says the draft is not binding.
     *
     * @return array<string, mixed>
     */
    public static function snapshotTerms(Agreement $agreement, ?FinancialPolicyVersion $template): array
    {
        $agreement->loadMissing(['offer', 'service', 'industrialFactory', 'serviceProvider', 'revenueSharePolicyVersion']);

        return [
            'legal_status' => 'draft_not_binding',
            'generated_on' => PolicyCalendar::today()->toDateString(),
            'agreement' => [
                'id' => $agreement->id,
                'concluded_at' => $agreement->concluded_at->toIso8601ZuluString(),
                'service' => ['code' => $agreement->service?->code, 'name_ar' => $agreement->service?->name_ar],
                'price_amount' => $agreement->price_amount,
                'currency' => $agreement->currency,
                'offer' => $agreement->offer === null ? null : [
                    'id' => $agreement->offer->id,
                    'version' => $agreement->offer->version,
                    'scope' => $agreement->offer->scope,
                    'deliverables' => $agreement->offer->deliverables,
                    'duration_days' => $agreement->offer->duration_days,
                ],
            ],
            'parties' => [
                'factory' => ['id' => $agreement->factory_id, 'name' => $agreement->industrialFactory?->name],
                'service_provider' => ['id' => $agreement->service_provider_id, 'name' => $agreement->serviceProvider?->name],
            ],
            'policy_versions' => [
                'revenue_share' => $agreement->revenueSharePolicyVersion?->reference(),
                'contract_template' => $template?->reference(),
            ],
            'template' => $template === null ? null : [
                'title_ar' => $template->parameters['title_ar'],
                'parties' => $template->parameters['parties'],
                'duration_months' => $template->parameters['duration_months'],
                'knowledge_transfer_min_trainees' => $template->parameters['knowledge_transfer_min_trainees'],
                'clauses' => $template->parameters['clauses'],
            ],
        ];
    }

    /**
     * @return BelongsTo<FinancialPolicyVersion, $this>
     */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(FinancialPolicyVersion::class, 'contract_template_version_id');
    }

    /**
     * Apply a status change the workflow allows, or refuse it with 409.
     */
    public function moveTo(ContractStatus $next, ?string $reason, User $actor): void
    {
        if (! $this->status->canBecome($next)) {
            throw new ConflictHttpException("This contract is {$this->status->value} and cannot become {$next->value}.");
        }

        $this->status = $next;
        $this->status_reason = $reason;
        $this->status_changed_by_user_id = $actor->id;
        $this->status_changed_at = now();
        $this->save();
    }
}
