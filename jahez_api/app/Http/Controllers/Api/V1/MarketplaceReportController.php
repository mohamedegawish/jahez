<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgreementReviewStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ProviderRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MarketplaceReportRequest;
use App\Models\Agreement;
use App\Models\CatalogService;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\ProviderRequest;
use App\Models\ProviderRequestMessage;
use App\Models\ProviderRequestTransition;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Marketplace reports (ADR-020) computed from stored records only, for one period:
 * a provider sees its own threads, agreements and invoices; a factory its own; IMC
 * administrators all of them. Counts are counts; rates are given only when their base is
 * not zero, with the numerator and base beside them. Money is summed per currency and
 * only from issued invoices, whose totals are fixed; drafts have no authoritative total.
 * Dates are UTC days.
 */
class MarketplaceReportController extends Controller
{
    public function __invoke(MarketplaceReportRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $from = $request->from();
        $to = $request->to();
        $serviceCode = $request->filled('filter.service') ? $request->string('filter.service')->toString() : null;
        $serviceId = $serviceCode === null ? null : (int) CatalogService::query()->where('code', $serviceCode)->value('id');

        $threads = $this->threads($user, $serviceId)->whereBetween('provider_requests.created_at', [$from, $to]);
        $agreements = $this->agreements($user, $serviceId)->whereBetween('agreements.concluded_at', [$from, $to]);

        return response()->json(['data' => [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => 'UTC'],
            'requests' => $this->requestFigures(clone $threads),
            'response_time' => $this->responseTimes(clone $threads),
            'conversion' => $this->conversion(clone $threads),
            'agreements' => $this->agreementFigures(clone $agreements),
            'invoices' => $this->invoiceFigures($user, $serviceId, $from, $to),
            'activity_by_month' => $this->activity($user, $serviceId, $from, $to),
        ]]);
    }

    /**
     * @return Builder<ProviderRequest>
     */
    private function threads(User $user, ?int $serviceId): Builder
    {
        return ProviderRequest::query()
            ->join('service_requests', 'service_requests.id', '=', 'provider_requests.service_request_id')
            ->when($user->service_provider_id !== null, fn (Builder $query) => $query->where('provider_requests.service_provider_id', $user->service_provider_id))
            ->when($user->factory_id !== null, fn (Builder $query) => $query->where('service_requests.factory_id', $user->factory_id))
            ->when($serviceId !== null, fn (Builder $query) => $query->where('service_requests.catalog_service_id', $serviceId));
    }

    /**
     * @return Builder<Agreement>
     */
    private function agreements(User $user, ?int $serviceId): Builder
    {
        return Agreement::query()
            ->when($user->service_provider_id !== null, fn (Builder $query) => $query->where('agreements.service_provider_id', $user->service_provider_id))
            ->when($user->factory_id !== null, fn (Builder $query) => $query->where('agreements.factory_id', $user->factory_id))
            ->when($serviceId !== null, fn (Builder $query) => $query->where('agreements.catalog_service_id', $serviceId));
    }

    /**
     * @param  Builder<ProviderRequest>  $threads
     * @return array<string, mixed>
     */
    private function requestFigures(Builder $threads): array
    {
        $byStatus = (clone $threads)->selectRaw('provider_requests.status AS status, COUNT(*) AS total')->groupBy('provider_requests.status')->pluck('total', 'status');
        $statuses = [];
        foreach (ProviderRequestStatus::cases() as $status) {
            $statuses[$status->value] = (int) ($byStatus[$status->value] ?? 0);
        }

        $byMonth = (clone $threads)
            ->selectRaw("DATE_FORMAT(provider_requests.created_at, '%Y-%m') AS month, COUNT(*) AS total")
            ->groupBy('month')->orderBy('month')->pluck('total', 'month');

        $byService = (clone $threads)
            ->join('catalog_services', 'catalog_services.id', '=', 'service_requests.catalog_service_id')
            ->selectRaw('catalog_services.code AS code, catalog_services.name_ar AS name_ar, COUNT(*) AS total')
            ->groupBy('catalog_services.code', 'catalog_services.name_ar')
            ->orderByDesc('total')->orderBy('catalog_services.code')
            ->limit(10)
            ->toBase()
            ->get();

        return [
            'total' => array_sum($statuses),
            'by_status' => $statuses,
            'by_month' => $byMonth->map(fn ($total, $month): array => ['month' => (string) $month, 'count' => (int) $total])->values()->all(),
            'top_services' => $byService->map(fn ($row): array => ['code' => $row->code, 'name_ar' => $row->name_ar, 'count' => (int) $row->total])->all(),
        ];
    }

    /**
     * Hours from a request reaching the provider to the provider's first answer (accept
     * or decline), from the recorded thread history.
     *
     * @param  Builder<ProviderRequest>  $threads
     * @return array<string, mixed>
     */
    private function responseTimes(Builder $threads): array
    {
        $ids = (clone $threads)->pluck('provider_requests.id');
        $hours = ProviderRequestTransition::query()
            ->whereIn('provider_request_transitions.provider_request_id', $ids)
            ->whereIn('to_status', [ProviderRequestStatus::Accepted->value, ProviderRequestStatus::Declined->value])
            ->join('provider_requests', 'provider_requests.id', '=', 'provider_request_transitions.provider_request_id')
            ->selectRaw('provider_request_transitions.provider_request_id, MIN(provider_request_transitions.created_at) AS answered_at, MIN(provider_requests.created_at) AS received_at')
            ->groupBy('provider_request_transitions.provider_request_id')
            ->toBase()
            ->get()
            ->map(fn ($row): float => max(0, Carbon::parse($row->received_at)->diffInSeconds(Carbon::parse($row->answered_at))) / 3600)
            ->sort()
            ->values();

        $count = $hours->count();

        return [
            'answered_count' => $count,
            'average_hours' => $count === 0 ? null : round((float) $hours->avg(), 1),
            'median_hours' => $count === 0 ? null : round((float) ($count % 2 === 1 ? $hours[intdiv($count, 2)] : ($hours[$count / 2 - 1] + $hours[$count / 2]) / 2), 1),
        ];
    }

    /**
     * @param  Builder<ProviderRequest>  $threads
     * @return array<string, mixed>
     */
    private function conversion(Builder $threads): array
    {
        $total = (clone $threads)->count();
        $agreed = (clone $threads)->where('provider_requests.status', ProviderRequestStatus::Agreed)->count();
        $approved = (clone $threads)
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('agreements')
                ->join('agreement_reviews', 'agreement_reviews.agreement_id', '=', 'agreements.id')
                ->whereColumn('agreements.provider_request_id', 'provider_requests.id')
                ->where('agreement_reviews.decision', AgreementReviewStatus::Approved->value))
            ->count();

        return [
            'requests' => $total,
            'agreed' => $agreed,
            'imc_approved' => $approved,
            'agreed_rate_percent' => $total === 0 ? null : round($agreed * 100 / $total, 1),
            'approved_rate_percent' => $total === 0 ? null : round($approved * 100 / $total, 1),
        ];
    }

    /**
     * @param  Builder<Agreement>  $agreements
     * @return array<string, mixed>
     */
    private function agreementFigures(Builder $agreements): array
    {
        $total = (clone $agreements)->count();
        $decisions = (clone $agreements)
            ->join('agreement_reviews', 'agreement_reviews.agreement_id', '=', 'agreements.id')
            ->selectRaw('agreement_reviews.decision AS decision, COUNT(*) AS total')
            ->groupBy('agreement_reviews.decision')
            ->pluck('total', 'decision');
        $approved = (int) ($decisions[AgreementReviewStatus::Approved->value] ?? 0);
        $rejected = (int) ($decisions[AgreementReviewStatus::Rejected->value] ?? 0);

        return [
            'total' => $total,
            'by_review_status' => [
                AgreementReviewStatus::Pending->value => $total - $approved - $rejected,
                AgreementReviewStatus::Approved->value => $approved,
                AgreementReviewStatus::Rejected->value => $rejected,
            ],
            'with_contract_draft' => (clone $agreements)->whereHas('activeContract')->count(),
            // Contract start and end dates are not modelled (OQ-17), so nothing can be "near expiry".
            'expiry' => ['status' => 'not_available', 'decision_needed' => 'OQ-17'],
        ];
    }

    /**
     * Invoice counts per status, by creation date in the period, and totals per currency
     * of issued invoices only (by issue date).
     *
     * @return array<string, mixed>
     */
    private function invoiceFigures(User $user, ?int $serviceId, Carbon $from, Carbon $to): array
    {
        $scoped = fn () => Invoice::query()
            ->join('agreements', 'agreements.id', '=', 'invoices.agreement_id')
            ->when($user->service_provider_id !== null, fn (Builder $query) => $query->where('agreements.service_provider_id', $user->service_provider_id))
            ->when($user->factory_id !== null, fn (Builder $query) => $query->where('agreements.factory_id', $user->factory_id))
            ->when($serviceId !== null, fn (Builder $query) => $query->where('agreements.catalog_service_id', $serviceId));

        $counts = $scoped()->whereBetween('invoices.created_at', [$from, $to])
            ->selectRaw('invoices.status AS status, COUNT(*) AS total')
            ->groupBy('invoices.status')
            ->pluck('total', 'status');
        $byStatus = [];
        foreach (InvoiceStatus::cases() as $status) {
            $byStatus[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        $issuedStatuses = [InvoiceStatus::Issued->value, InvoiceStatus::Paid->value, InvoiceStatus::Refunded->value];
        $totals = $scoped()->whereBetween('invoices.issued_at', [$from, $to])
            ->whereIn('invoices.status', $issuedStatuses)
            ->whereNotNull('invoices.total_amount')
            ->selectRaw('invoices.status AS status, invoices.currency AS currency, SUM(invoices.total_amount) AS amount, COUNT(*) AS total')
            ->groupBy('invoices.status', 'invoices.currency')
            ->orderBy('invoices.status')
            ->toBase()
            ->get()
            ->map(fn ($row): array => ['status' => $row->status, 'currency' => $row->currency, 'amount' => number_format((float) $row->amount, 2, '.', ''), 'count' => (int) $row->total])
            ->all();

        return [
            'by_status' => $byStatus,
            'issued_totals' => $totals,
        ];
    }

    /**
     * Messages and offer versions per month written by the caller's side (or by everyone
     * for IMC), in the period.
     *
     * @return list<array{month: string, messages: int, offers: int}>
     */
    private function activity(User $user, ?int $serviceId, Carbon $from, Carbon $to): array
    {
        $side = match (true) {
            $user->service_provider_id !== null => ProviderRequestMessage::SIDE_PROVIDER,
            $user->factory_id !== null => ProviderRequestMessage::SIDE_FACTORY,
            default => null,
        };
        $threadIds = $this->threads($user, $serviceId)->select('provider_requests.id');

        $messages = ProviderRequestMessage::query()
            ->whereIn('provider_request_id', $threadIds)
            ->when($side !== null, fn (Builder $query) => $query->where('author_side', $side))
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS total")
            ->groupBy('month')
            ->pluck('total', 'month');

        $offers = $side === ProviderRequestMessage::SIDE_FACTORY
            ? collect()
            : Offer::query()
                ->whereIn('provider_request_id', $this->threads($user, $serviceId)->select('provider_requests.id'))
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS total")
                ->groupBy('month')
                ->pluck('total', 'month');

        $months = $messages->keys()->merge($offers->keys())->unique()->sort()->values();

        return array_values($months->map(fn ($month): array => [
            'month' => (string) $month,
            'messages' => (int) ($messages[$month] ?? 0),
            'offers' => (int) ($offers[$month] ?? 0),
        ])->all());
    }
}
