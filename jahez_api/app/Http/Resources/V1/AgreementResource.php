<?php

namespace App\Http\Resources\V1;

use App\Enums\Permission;
use App\Models\Agreement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An agreement (ADR-017): the accepted offer version between a factory and a provider.
 * `binding` is always false: it is not a contract, an invoice or a payment (OQ-15 to
 * OQ-17). The terms and price are shown to the two parties and to IMC reviewers, who
 * decide on the agreement (ADR-020); other IMC administrators see the rest (PROPOSED,
 * OQ-39). Negotiation messages are never part of it.
 *
 * `imc_review.status`: `pending` (awaiting IMC), `approved` or `rejected` (final, with
 * the reason). `next_step` tells a client what the workflow allows next.
 *
 * Expects industrialFactory, serviceProvider, service, offer, concludedBy,
 * activeContract, review.reviewedBy and serviceRequest to be loaded.
 *
 * @mixin Agreement
 */
class AgreementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isParty = $user instanceof User && $this->sideOf($user) !== null;
        $isReviewer = $user instanceof User && $user->hasPermission(Permission::AgreementsReview);
        $reviewStatus = $this->reviewStatus()->value;
        $approvalRequired = (bool) config('jahez.agreements.imc_approval_required', true);

        return [
            'id' => $this->id,
            'service_request_id' => $this->service_request_id,
            'provider_request_id' => $this->provider_request_id,
            'service_request' => $this->serviceRequest === null ? null : ['id' => $this->serviceRequest->id, 'title' => $this->serviceRequest->title],
            'factory' => $this->industrialFactory === null ? null : ['id' => $this->industrialFactory->id, 'name' => $this->industrialFactory->name],
            'provider' => $this->serviceProvider === null ? null : ['id' => $this->serviceProvider->id, 'name' => $this->serviceProvider->name],
            'service' => $this->service === null ? null : ['code' => $this->service->code, 'name_ar' => $this->service->name_ar],
            'binding' => false,
            ...(($isParty || $isReviewer) ? [
                'terms' => $this->offer === null ? null : [
                    'offer_id' => $this->offer->id,
                    'offer_version' => $this->offer->version,
                    'scope' => $this->offer->scope,
                    'deliverables' => $this->offer->deliverables,
                    'duration_days' => $this->offer->duration_days,
                ],
                'price' => ['amount' => $this->price_amount, 'currency' => $this->currency],
            ] : []),
            'imc_review' => [
                'required' => $approvalRequired,
                'status' => $reviewStatus,
                'reason' => $this->review?->reason,
                'decided_at' => $this->review?->decided_at->toIso8601ZuluString(),
                'reviewed_by' => $this->review?->reviewedBy === null ? null : ['id' => $this->review->reviewedBy->id, 'name' => $this->review->reviewedBy->name],
            ],
            'contract' => $this->activeContract === null ? null : [
                'id' => $this->activeContract->id,
                'version' => $this->activeContract->version,
                'status' => $this->activeContract->status->value,
            ],
            'next_step' => match (true) {
                $approvalRequired && $reviewStatus === 'pending' => 'awaiting_imc_review',
                $approvalRequired && $reviewStatus === 'rejected' => 'rejected_by_imc',
                $this->activeContract === null => 'contract_draft',
                default => 'contract_drafted',
            },
            'concluded_by' => $this->concludedBy === null ? null : ['id' => $this->concludedBy->id, 'name' => $this->concludedBy->name],
            'concluded_at' => $this->concluded_at->toIso8601ZuluString(),
            // ADR-023: `legacy` agreements predate the managed policies; for the others the
            // revenue-share version in effect when they were concluded, or null if none was.
            'policy_basis' => $this->policy_basis->value,
            'revenue_share_policy_version_id' => $this->revenue_share_policy_version_id,
        ];
    }
}
