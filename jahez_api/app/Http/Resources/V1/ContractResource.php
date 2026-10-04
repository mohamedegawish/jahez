<?php

namespace App\Http\Resources\V1;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A contract draft (ADR-017). Under the OQ-17 interim it is never binding and cannot be
 * signed: `legal_status` is always "draft_not_binding" and `signature.status`
 * "not_available". The knowledge-transfer commitment (DOC §6) and the notes are shown
 * to the two parties only, as is the snapshot of the agreed terms and template clauses
 * (ADR-023); IMC administrators see the status and the template version (PROPOSED, OQ-39). Expects
 * agreement and draftedBy to be loaded.
 *
 * @mixin Contract
 */
class ContractResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isParty = $user instanceof User && $this->agreement?->sideOf($user) !== null;

        return [
            'id' => $this->id,
            'agreement_id' => $this->agreement_id,
            'version' => $this->version,
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'binding' => false,
            'legal_status' => 'draft_not_binding',
            'signature' => ['status' => 'not_available', 'decision_needed' => 'OQ-17'],
            // ADR-023: legacy drafts predate the snapshot; others name the template version.
            'policy_basis' => $this->policy_basis->value,
            'template_version' => $this->terms_snapshot['policy_versions']['contract_template'] ?? null,
            ...($isParty ? [
                'knowledge_transfer' => [
                    'trainees' => $this->knowledge_transfer_trainees,
                    'training_plan' => $this->knowledge_transfer_plan,
                    'commitment_memo' => ['status' => 'not_available', 'decision_needed' => 'OQ-10, OQ-17'],
                ],
                'notes' => $this->notes,
                'terms_snapshot' => $this->terms_snapshot,
            ] : []),
            'drafted_by' => $this->draftedBy === null ? null : ['id' => $this->draftedBy->id, 'name' => $this->draftedBy->name],
            'status_changed_at' => $this->status_changed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
