<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\BillingPolicy;
use App\Billing\PolicyCalendar;
use App\Billing\PolicyContext;
use App\Billing\PolicyNotConfiguredException;
use App\Billing\PolicyParameters;
use App\Billing\PolicyResolver;
use App\Enums\AuditEvent;
use App\Enums\FinancialPolicyKind;
use App\Enums\InvoiceIssuer;
use App\Enums\InvoiceStatus;
use App\Enums\PolicyBasis;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelInvoiceRequest;
use App\Http\Requests\Api\V1\ListInvoicesRequest;
use App\Http\Requests\Api\V1\StoreInvoiceRequest;
use App\Http\Resources\V1\InvoiceResource;
use App\Models\Agreement;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Invoices for agreements (ADR-017, ADR-023). Drafting needs an approved invoicing
 * policy; issuing also needs approved tax and payment-terms policies (and a revenue
 * share when the invoicing policy requires one). While a policy is missing the operation
 * is refused with 409 policy_not_configured. `partially_paid`, `paid` and `refunded`
 * come only from verified payments or authorised manual entries.
 */
class InvoiceController extends Controller
{
    private const RELATIONS = ['lines'];

    /** The agreement's parties and service, shown with each invoice. */
    private const PARTY_RELATIONS = ['agreement.industrialFactory', 'agreement.serviceProvider', 'agreement.service'];

    public function index(ListInvoicesRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        $invoices = Invoice::query()
            ->when($user->factory_id !== null, fn (Builder $query) => $query->whereHas('agreement', fn (Builder $agreements) => $agreements->where('factory_id', $user->factory_id)))
            ->when($user->service_provider_id !== null, fn (Builder $query) => $query->whereHas('agreement', fn (Builder $agreements) => $agreements->where('service_provider_id', $user->service_provider_id)))
            ->when($request->input('filter.status'), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->input('filter.agreement'), fn (Builder $query, int|string $id) => $query->where('agreement_id', (int) $id))
            ->when($request->input('filter.service'), fn (Builder $query, string $code) => $query->whereHas('agreement.service', fn (Builder $services) => $services->where('code', $code)))
            ->when($request->input('filter.from'), fn (Builder $query, string $day) => $query->where('created_at', '>=', Carbon::parse($day, 'UTC')->startOfDay()))
            ->when($request->input('filter.to'), fn (Builder $query, string $day) => $query->where('created_at', '<=', Carbon::parse($day, 'UTC')->endOfDay()))
            ->when($request->input('filter.counterparty'), fn (Builder $query, int|string $id) => $query->whereHas('agreement', fn (Builder $agreements) => $agreements->where(
                $user->factory_id !== null ? 'service_provider_id' : 'factory_id',
                (int) $id,
            )))
            ->with([...self::RELATIONS, ...self::PARTY_RELATIONS])
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return InvoiceResource::collection($invoices);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        Gate::authorize('view', $invoice);

        return new InvoiceResource($invoice->load([...self::RELATIONS, ...self::PARTY_RELATIONS]));
    }

    /**
     * Draft the agreement's invoice: one line, the agreed service at the agreed price.
     * The invoicing policy in effect today decides who issues it and who pays (ADR-023),
     * and its version is stored with the draft. An agreement has at most one invoice that
     * is not cancelled (PROPOSED: no billing schedule is decided, OQ-16). The agreement
     * row is locked while checking.
     */
    public function store(StoreInvoiceRequest $request, Agreement $agreement, #[CurrentUser] User $user): JsonResponse
    {
        $invoice = DB::transaction(function () use ($agreement, $user): Invoice {
            $locked = Agreement::query()->with('service')->lockForUpdate()->findOrFail($agreement->id);
            $locked->ensureApprovedByImc();
            $invoicing = PolicyResolver::require(FinancialPolicyKind::Invoicing, PolicyContext::forAgreement($locked), PolicyCalendar::today(), 'draft an invoice', 'إعداد مسودة فاتورة لهذه الاتفاقية', lock: true);
            $issuer = InvoiceIssuer::from((string) $invoicing->parameters['issuer']);
            Gate::authorize('create', [Invoice::class, $locked]);

            if (! in_array(PolicyParameters::INVOICE_TYPE_AGREEMENT_SERVICE, (array) $invoicing->parameters['invoice_types'], true)) {
                throw new PolicyNotConfiguredException('The invoicing policy in effect does not allow invoices for agreed services.', 'OQ-16', 'سياسة الفوترة السارية لا تسمح بإصدار فواتير الخدمات المتفق عليها.', [FinancialPolicyKind::Invoicing->value]);
            }
            if ($invoicing->parameters['currency'] !== $locked->currency) {
                throw new PolicyNotConfiguredException("The invoicing policy in effect bills in {$invoicing->parameters['currency']}, not in the agreement's {$locked->currency}.", 'OQ-16', 'عملة سياسة الفوترة السارية تختلف عن عملة الاتفاقية.', [FinancialPolicyKind::Invoicing->value]);
            }

            $existing = Invoice::query()->where('agreement_id', $locked->id)->where('status', '!=', InvoiceStatus::Cancelled)->first();

            if ($existing !== null) {
                throw new ConflictHttpException("This agreement already has an invoice (id {$existing->id}, {$existing->status->value}).");
            }

            $invoice = new Invoice;
            $invoice->agreement_id = $locked->id;
            $invoice->issuer = $issuer;
            $invoice->payer = (string) $invoicing->parameters['payer'];
            $invoice->currency = $locked->currency;
            $invoice->policy_basis = PolicyBasis::Policy;
            $invoice->invoicing_policy_version_id = $invoicing->id;
            $invoice->created_by_user_id = $user->id;
            $invoice->save();
            $invoice->addLine((string) $locked->service?->name_ar, 1, $locked->price_amount);

            AuditLog::record(AuditEvent::InvoiceDrafted, $user, $invoice, ['agreement_id' => $locked->id, 'issuer' => $issuer->value, 'invoicing_policy_version_id' => $invoicing->id]);

            return $invoice;
        });

        return (new InvoiceResource($invoice->refresh()->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /**
     * Issue a draft under the approved policies in effect today: number, taxes, fees,
     * total, due date and the informational revenue share are fixed, the lines frozen,
     * and the policy versions stored (ADR-023). Every missing policy is reported at once.
     * A draft made before policies were managed in the database, or whose issuer the
     * current invoicing policy no longer names, is refused: it is cancelled and drafted
     * again.
     */
    public function issue(Invoice $invoice, #[CurrentUser] User $user): InvoiceResource
    {
        Gate::authorize('manage', $invoice);

        DB::transaction(function () use ($invoice, $user): void {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $agreement = $locked->agreement()->firstOrFail();

            if ($locked->status === InvoiceStatus::Draft && $locked->policy_basis === PolicyBasis::Legacy) {
                throw new ConflictHttpException('This draft was prepared before financial policies were managed by IMC; cancel it and draft the invoice again.');
            }

            $policies = BillingPolicy::issuingPolicies($agreement, PolicyCalendar::today(), lock: true);
            if ($policies['invoicing']->parameters['issuer'] !== $locked->issuer->value) {
                throw new ConflictHttpException('The invoicing policy in effect names a different issuer than this draft; cancel it and draft the invoice again.');
            }

            $locked->issue($user, $policies);

            AuditLog::record(AuditEvent::InvoiceIssued, $user, $locked, [
                'number' => $locked->number,
                'policy_versions' => array_filter([
                    'invoicing' => $locked->invoicing_policy_version_id,
                    'tax' => $locked->tax_policy_version_id,
                    'payment_terms' => $locked->payment_terms_policy_version_id,
                    'revenue_share' => $locked->revenue_share_policy_version_id,
                ]),
            ]);
            MarketplaceNotifications::invoiceIssued($locked, $agreement);
        });

        return new InvoiceResource($invoice->refresh()->load(self::RELATIONS));
    }

    /**
     * Withdraw a draft. An issued invoice cannot be cancelled or voided until credit
     * notes are decided (OQ-16).
     */
    public function cancel(CancelInvoiceRequest $request, Invoice $invoice, #[CurrentUser] User $user): InvoiceResource
    {
        DB::transaction(function () use ($request, $invoice, $user): void {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status === InvoiceStatus::Issued) {
                throw new PolicyNotConfiguredException('An issued invoice cannot be cancelled or voided until credit notes are decided.', 'OQ-16');
            }

            $locked->moveTo(InvoiceStatus::Cancelled, $request->reason());

            AuditLog::record(AuditEvent::InvoiceCancelled, $user, $locked, ['reason' => $request->reason()]);
        });

        return new InvoiceResource($invoice->refresh()->load(self::RELATIONS));
    }
}
