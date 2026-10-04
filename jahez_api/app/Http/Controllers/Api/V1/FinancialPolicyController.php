<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\FinancialPolicyLifecycle;
use App\Billing\InvoiceCalculator;
use App\Billing\Money;
use App\Billing\PolicyResolver;
use App\Enums\FinancialPolicyKind;
use App\Enums\FinancialPolicyScope;
use App\Enums\FinancialPolicyVersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListFinancialPoliciesRequest;
use App\Http\Requests\Api\V1\ResolveFinancialPolicyRequest;
use App\Http\Requests\Api\V1\StoreFinancialPolicyRequest;
use App\Http\Resources\V1\FinancialPolicyResource;
use App\Http\Resources\V1\FinancialPolicyVersionResource;
use App\Models\FinancialPolicy;
use App\Models\FinancialPolicyVersion;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * IMC's financial and contract policies (ADR-023): the «الإعدادات المالية والتعاقدية»
 * section. Reading needs financial_policies.view; creating a policy needs
 * financial_policies.manage. Every value is validated here and again by
 * App\Billing\FinancialPolicyLifecycle; the client never sends a status, an approval, a
 * calculated amount or a version reference that is trusted.
 */
class FinancialPolicyController extends Controller
{
    private const SCOPE_RELATIONS = ['sector', 'catalogService', 'serviceProvider'];

    public function index(ListFinancialPoliciesRequest $request): AnonymousResourceCollection
    {
        $policies = FinancialPolicy::query()
            ->when($request->input('filter.kind'), fn (Builder $query, string $kind) => $query->where('kind', $kind))
            ->when($request->input('filter.scope_type'), fn (Builder $query, string $scope) => $query->where('scope_type', $scope))
            ->with(self::SCOPE_RELATIONS)
            ->orderBy('kind')
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        self::withSummaries($policies->getCollection());

        return FinancialPolicyResource::collection($policies);
    }

    /**
     * Create a policy and its first draft version. 201 with the draft.
     */
    public function store(StoreFinancialPolicyRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $version = FinancialPolicyLifecycle::createPolicy(
            $user,
            FinancialPolicyKind::from($request->string('kind')->toString()),
            FinancialPolicyScope::from($request->string('scope_type')->toString()),
            $request->scopeId(),
            $request->string('name_ar')->toString(),
            $request->filled('description_ar') ? $request->string('description_ar')->toString() : null,
            $request->draft(),
        );

        return (new FinancialPolicyVersionResource($version->load(['policy' => fn ($query) => $query->with(self::SCOPE_RELATIONS), 'createdBy'])))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(FinancialPolicy $financialPolicy): FinancialPolicyResource
    {
        Gate::authorize('view', $financialPolicy);

        $financialPolicy->load([...self::SCOPE_RELATIONS, 'versions.createdBy', 'versions.submittedBy', 'versions.decidedBy']);
        self::withSummaries(new Collection([$financialPolicy]));

        return new FinancialPolicyResource($financialPolicy);
    }

    /**
     * The version of a kind that applies to a service, sectors and provider on a day
     * (today by default), or null with the Arabic reason nothing applies.
     */
    public function resolve(ResolveFinancialPolicyRequest $request): JsonResponse
    {
        $kind = FinancialPolicyKind::from($request->string('kind')->toString());
        $day = $request->day();
        $version = PolicyResolver::resolve($kind, $request->context(), $day);
        $version?->load(['policy' => fn ($query) => $query->with(self::SCOPE_RELATIONS), 'createdBy', 'submittedBy', 'decidedBy']);

        return response()->json(['data' => [
            'kind' => $kind->value,
            'date' => $day->toDateString(),
            'version' => $version === null ? null : (new FinancialPolicyVersionResource($version))->resolve($request),
            'reason_ar' => $version === null ? PolicyResolver::missing($kind, $day, 'apply it', 'تطبيقها')->reasonAr : null,
        ]]);
    }

    /**
     * What an invoice of the given subtotal would come to under the policies that apply
     * to the context on the day: the server's calculation, with every component and the
     * versions used. Nothing is stored. Refused with 409 while no tax policy applies.
     */
    public function preview(ResolveFinancialPolicyRequest $request): JsonResponse
    {
        $context = $request->context();
        $day = $request->day();
        $tax = PolicyResolver::require(FinancialPolicyKind::Tax, $context, $day, 'calculate an invoice', 'حساب الفاتورة');
        $share = PolicyResolver::resolve(FinancialPolicyKind::RevenueShare, $context, $day);
        $terms = PolicyResolver::resolve(FinancialPolicyKind::PaymentTerms, $context, $day);
        $invoicing = PolicyResolver::resolve(FinancialPolicyKind::Invoicing, $context, $day);

        return response()->json(['data' => [
            'date' => $day->toDateString(),
            'calculation' => InvoiceCalculator::calculate(Money::toMinor($request->string('amount')->toString()), $tax->parameters, $share?->parameters),
            'due_date' => $terms === null ? null : InvoiceCalculator::dueDate($day, $terms->parameters)->toDateString(),
            'policy_versions' => [
                'invoicing' => $invoicing?->reference(),
                'tax' => $tax->reference(),
                'payment_terms' => $terms?->reference(),
                'revenue_share' => $share?->reference(),
            ],
            'missing_ar' => array_values(array_filter([
                $invoicing === null ? 'لا توجد سياسة فوترة معتمدة سارية؛ لا يمكن إعداد الفاتورة.' : null,
                $terms === null ? 'لا توجد شروط سداد معتمدة سارية؛ لا يمكن إصدار الفاتورة.' : null,
                $share === null ? 'لا توجد حصة إيرادات معتمدة سارية؛ لن تُحسب حصة الوزارة.' : null,
            ])),
        ]]);
    }

    /**
     * Sets the `currentVersion` (in effect today) and `openVersion` (draft or pending)
     * relations of each policy, with two queries for the whole page.
     *
     * @param  Collection<int, FinancialPolicy>  $policies
     */
    private static function withSummaries(Collection $policies): void
    {
        $versions = FinancialPolicyVersion::query()
            ->whereIn('financial_policy_id', $policies->modelKeys())
            ->whereIn('status', [...FinancialPolicyVersionStatus::resolvable(), FinancialPolicyVersionStatus::Draft, FinancialPolicyVersionStatus::PendingApproval])
            ->orderByDesc('version')
            ->get()
            ->groupBy('financial_policy_id');

        foreach ($policies as $policy) {
            $own = $versions->get($policy->id, new Collection);
            $policy->setRelation('currentVersion', $own->first(fn (FinancialPolicyVersion $version): bool => $version->effectiveStatus() === 'active'));
            $policy->setRelation('openVersion', $own->first(fn (FinancialPolicyVersion $version): bool => $version->status->isOpen()));
        }
    }
}
