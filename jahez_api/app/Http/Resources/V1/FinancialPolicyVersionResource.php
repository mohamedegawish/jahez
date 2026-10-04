<?php

namespace App\Http\Resources\V1;

use App\Enums\FinancialPolicyVersionStatus;
use App\Models\FinancialPolicyVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * A financial policy version (ADR-023). `status` is the stored status; `effective_status`
 * also says whether an approved version is scheduled, active or expired today. `actions`
 * tells the current administrator what they may do now; the server checks again on
 * every action.
 *
 * @mixin FinancialPolicyVersion
 */
class FinancialPolicyVersionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'policy_id' => $this->financial_policy_id,
            'policy' => $this->whenLoaded('policy', fn (): FinancialPolicyResource => new FinancialPolicyResource($this->policy)),
            'version' => $this->version,
            'status' => $this->status->value,
            'status_label_ar' => $this->status->labelAr(),
            'effective_status' => $this->effectiveStatus(),
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'parameters' => $this->parameters,
            'change_reason' => $this->change_reason,
            'created_by' => $this->person($this->createdBy),
            'submitted_by' => $this->person($this->submittedBy),
            'submitted_at' => $this->submitted_at?->toIso8601ZuluString(),
            'decided_by' => $this->person($this->decidedBy),
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'decision_note' => $this->decision_note,
            'superseded_by_version_id' => $this->superseded_by_version_id,
            'status_reason' => $this->status_reason,
            'status_changed_at' => $this->status_changed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
            'actions' => $user instanceof User ? $this->actionsFor($user) : null,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function actionsFor(User $user): array
    {
        $gate = Gate::forUser($user);
        $status = $this->status;
        $scheduled = $this->effectiveStatus() === 'scheduled';

        return [
            'edit' => $status === FinancialPolicyVersionStatus::Draft && $gate->allows('prepare', $this->resource),
            'submit' => $status === FinancialPolicyVersionStatus::Draft && $gate->allows('prepare', $this->resource),
            'approve' => $status === FinancialPolicyVersionStatus::PendingApproval && $gate->allows('decide', $this->resource),
            'reject' => $status === FinancialPolicyVersionStatus::PendingApproval && $gate->allows('decide', $this->resource),
            'archive' => in_array($status, [FinancialPolicyVersionStatus::Draft, FinancialPolicyVersionStatus::Rejected], true) || ($status === FinancialPolicyVersionStatus::Approved && $scheduled)
                ? $gate->allows('archive', $this->resource) : false,
            'end' => $status === FinancialPolicyVersionStatus::Approved && ! $scheduled && $this->effectiveStatus() !== 'expired' && $gate->allows('end', $this->resource),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function person(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }
}
