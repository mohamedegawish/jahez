<?php

namespace App\Http\Resources\V1;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An invoice (ADR-017). Amounts are decimal strings in `currency`. A draft has no
 * number, tax or total yet. `revenue_share` is informational: "not_configured" until
 * the owner decides the rule (OQ-15); nothing is paid out. Expects lines to be loaded.
 *
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
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
            'agreement_id' => $this->agreement_id,
            'parties' => $this->whenLoaded('agreement', fn (): ?array => $this->agreement === null ? null : [
                'factory' => $this->agreement->relationLoaded('industrialFactory') && $this->agreement->industrialFactory !== null
                    ? ['id' => $this->agreement->industrialFactory->id, 'name' => $this->agreement->industrialFactory->name] : null,
                'provider' => $this->agreement->relationLoaded('serviceProvider') && $this->agreement->serviceProvider !== null
                    ? ['id' => $this->agreement->serviceProvider->id, 'name' => $this->agreement->serviceProvider->name] : null,
                'service' => $this->agreement->relationLoaded('service') && $this->agreement->service !== null
                    ? ['code' => $this->agreement->service->code, 'name_ar' => $this->agreement->service->name_ar] : null,
            ]),
            // One invoice type exists: the invoice for an agreed service (ADR-017). No
            // commission or ministry-fee invoice is generated (OQ-15).
            'type' => $this->type,
            // Set when the invoice is issued, from the approved payment terms (ADR-023).
            'due_date' => $this->due_date?->toDateString(),
            'is_overdue' => $this->isOverdue(),
            'number' => $this->number,
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'issuer' => $this->issuer->value,
            'payer' => $this->payer,
            // `legacy`: made before policies were managed in the database; no policy
            // version is referenced and nothing is recalculated (ADR-023).
            'policy_basis' => $this->policy_basis->value,
            'policy_versions' => $this->calculation['policy_versions'] ?? [
                'invoicing' => $this->invoicing_policy_version_id === null ? null : ['id' => $this->invoicing_policy_version_id],
                'tax' => null,
                'payment_terms' => null,
                'revenue_share' => null,
            ],
            'currency' => $this->currency,
            'lines' => $this->lines->map(fn (InvoiceLine $line): array => [
                'id' => $line->id,
                'position' => $line->position,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_amount' => $line->unit_amount,
                'line_amount' => $line->line_amount,
            ])->values()->all(),
            'subtotal' => $this->subtotal_amount,
            'tax' => $this->tax_rate_percent === null ? null : ['rate_percent' => $this->tax_rate_percent, 'amount' => $this->tax_amount],
            'fees' => $this->fees_amount,
            'total' => $this->total_amount,
            'amount_paid' => $this->amount_paid,
            'outstanding' => $this->total_amount === null ? null : $this->outstandingAmount(),
            // The server's calculation, frozen at issue: net amount, each fee and tax with
            // its base, the revenue share and the policy versions used.
            'calculation' => $this->calculation,
            'revenue_share' => $this->revenue_share_percent === null
                ? ['status' => 'not_configured', 'decision_needed' => 'OQ-15']
                : ['status' => 'calculated', 'rate_percent' => $this->revenue_share_percent, 'amount' => $this->revenue_share_amount],
            'issued_at' => $this->issued_at?->toIso8601ZuluString(),
            'paid_at' => $this->paid_at?->toIso8601ZuluString(),
            'status_changed_at' => $this->status_changed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
