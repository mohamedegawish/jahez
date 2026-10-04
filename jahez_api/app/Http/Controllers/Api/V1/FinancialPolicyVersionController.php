<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\FinancialPolicyLifecycle;
use App\Billing\InvoiceCalculator;
use App\Billing\Money;
use App\Billing\PolicyCalendar;
use App\Enums\FinancialPolicyKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DecideFinancialPolicyVersionRequest;
use App\Http\Requests\Api\V1\PreviewFinancialPolicyVersionRequest;
use App\Http\Requests\Api\V1\StoreFinancialPolicyVersionRequest;
use App\Http\Requests\Api\V1\UpdateFinancialPolicyVersionRequest;
use App\Http\Resources\V1\FinancialPolicyVersionResource;
use App\Models\AuditLog;
use App\Models\FinancialPolicy;
use App\Models\FinancialPolicyVersion;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The versions of a financial policy and their lifecycle (ADR-023): draft, edit,
 * submit, approve or reject (by a second administrator), archive, end. Each action is
 * delegated to App\Billing\FinancialPolicyLifecycle, which authorizes it again.
 */
class FinancialPolicyVersionController extends Controller
{
    private const RELATIONS = ['policy.sector', 'policy.catalogService', 'policy.serviceProvider', 'createdBy', 'submittedBy', 'decidedBy'];

    public function show(FinancialPolicyVersion $financialPolicyVersion): FinancialPolicyVersionResource
    {
        Gate::authorize('view', $financialPolicyVersion);

        return new FinancialPolicyVersionResource($financialPolicyVersion->load(self::RELATIONS));
    }

    public function store(StoreFinancialPolicyVersionRequest $request, FinancialPolicy $financialPolicy, #[CurrentUser] User $user): JsonResponse
    {
        $version = FinancialPolicyLifecycle::draftVersion($user, $financialPolicy, $request->draft());

        return (new FinancialPolicyVersionResource($version->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function update(UpdateFinancialPolicyVersionRequest $request, FinancialPolicyVersion $financialPolicyVersion, #[CurrentUser] User $user): FinancialPolicyVersionResource
    {
        $version = FinancialPolicyLifecycle::updateDraft($user, $financialPolicyVersion, $request->changes());

        return new FinancialPolicyVersionResource($version->load(self::RELATIONS));
    }

    public function submit(FinancialPolicyVersion $financialPolicyVersion, #[CurrentUser] User $user): FinancialPolicyVersionResource
    {
        Gate::authorize('prepare', $financialPolicyVersion);

        return new FinancialPolicyVersionResource(FinancialPolicyLifecycle::submit($user, $financialPolicyVersion)->load(self::RELATIONS));
    }

    public function approve(DecideFinancialPolicyVersionRequest $request, FinancialPolicyVersion $financialPolicyVersion, #[CurrentUser] User $user): FinancialPolicyVersionResource
    {
        return new FinancialPolicyVersionResource(FinancialPolicyLifecycle::approve($user, $financialPolicyVersion, $request->reason())->load(self::RELATIONS));
    }

    public function reject(DecideFinancialPolicyVersionRequest $request, FinancialPolicyVersion $financialPolicyVersion, #[CurrentUser] User $user): FinancialPolicyVersionResource
    {
        return new FinancialPolicyVersionResource(FinancialPolicyLifecycle::reject($user, $financialPolicyVersion, (string) $request->reason())->load(self::RELATIONS));
    }

    public function archive(DecideFinancialPolicyVersionRequest $request, FinancialPolicyVersion $financialPolicyVersion, #[CurrentUser] User $user): FinancialPolicyVersionResource
    {
        return new FinancialPolicyVersionResource(FinancialPolicyLifecycle::archive($user, $financialPolicyVersion, (string) $request->reason())->load(self::RELATIONS));
    }

    public function end(DecideFinancialPolicyVersionRequest $request, FinancialPolicyVersion $financialPolicyVersion, #[CurrentUser] User $user): FinancialPolicyVersionResource
    {
        $lastDay = PolicyCalendar::parse($request->string('effective_to')->toString());

        return new FinancialPolicyVersionResource(FinancialPolicyLifecycle::end($user, $financialPolicyVersion, $lastDay, (string) $request->reason())->load(self::RELATIONS));
    }

    /**
     * The audit trail of the version and of its policy, oldest first: who did what, when,
     * why, and the values before and after. IP addresses and e-mail addresses stay in the
     * audit log itself (audit_logs.view).
     */
    public function history(FinancialPolicyVersion $financialPolicyVersion): JsonResponse
    {
        Gate::authorize('view', $financialPolicyVersion);

        $entries = AuditLog::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $version) => $version->where('subject_type', 'financial_policy_version')->where('subject_id', $financialPolicyVersion->id))
                ->orWhere(fn (Builder $policy) => $policy->where('subject_type', 'financial_policy')->where('subject_id', $financialPolicyVersion->financial_policy_id)))
            ->with('actor')
            ->orderBy('id')
            ->limit(500)
            ->get();

        return response()->json(['data' => $entries->map(fn (AuditLog $entry): array => [
            'id' => $entry->id,
            'event' => $entry->event->value,
            'actor' => $entry->actor_user_id === null ? null : ['id' => $entry->actor_user_id, 'name' => $entry->actor?->name],
            'metadata' => $entry->metadata,
            'created_at' => $entry->created_at->toIso8601ZuluString(),
        ])->values()]);
    }

    /**
     * What an amount comes to under this version's values alone, whatever its status, so
     * the approver sees the effect before deciding. Nothing is stored.
     */
    public function preview(PreviewFinancialPolicyVersionRequest $request, FinancialPolicyVersion $financialPolicyVersion): JsonResponse
    {
        $version = $financialPolicyVersion;
        $kind = $version->policy()->firstOrFail()->kind;
        $amountMinor = Money::toMinor($request->string('amount')->toString());
        $today = PolicyCalendar::today();
        $noTax = ['prices_include_tax' => false, 'taxes' => [], 'fees' => []];

        $result = match ($kind) {
            FinancialPolicyKind::Tax => ['calculation' => InvoiceCalculator::calculate($amountMinor, $version->parameters, null)],
            FinancialPolicyKind::RevenueShare => ['calculation' => InvoiceCalculator::calculate($amountMinor, $noTax, $version->parameters)],
            FinancialPolicyKind::PaymentTerms => ['due_date_if_issued_today' => InvoiceCalculator::dueDate($today, $version->parameters)->toDateString()],
            FinancialPolicyKind::Invoicing => ['first_number_example' => sprintf('%s-%0'.(int) $version->parameters['number_padding'].'d', $version->parameters['number_prefix'], 1)],
            FinancialPolicyKind::ContractTemplate => ['clauses' => count($version->parameters['clauses']), 'parties' => $version->parameters['parties']],
        };

        return response()->json(['data' => ['version_id' => $version->id, 'kind' => $kind->value, ...$result]]);
    }
}
