<?php

namespace App\TransformationPlans;

use App\Enums\AgreementReviewStatus;
use App\Enums\PlanRequestProgress;
use App\Enums\ProviderRequestStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\Agreement;
use App\Models\ProviderRequest;
use App\Models\ServiceRequest;
use Illuminate\Support\Collection;

/**
 * Reads where a service request linked to a plan item stands in the existing workflow
 * (ADR-025): the request, its provider threads, the agreement and IMC's review. Nothing
 * here changes a status; the plan only shows the real state of the marketplace records.
 *
 * Callers load `providerRequests.agreement.review` and `providerRequests.serviceProvider`.
 */
final class RequestProgress
{
    /**
     * Relations the reads below need, for eager loading.
     */
    public const RELATIONS = ['providerRequests.agreement.review', 'providerRequests.serviceProvider'];

    public static function of(ServiceRequest $request): PlanRequestProgress
    {
        if ($request->status === ServiceRequestStatus::Cancelled) {
            return PlanRequestProgress::Cancelled;
        }

        if ($request->status === ServiceRequestStatus::Awarded) {
            $decisions = $request->providerRequests
                ->map(fn (ProviderRequest $thread): ?Agreement => $thread->agreement)
                ->filter()
                ->map(fn (Agreement $agreement): AgreementReviewStatus => $agreement->review === null ? AgreementReviewStatus::Pending : $agreement->review->decision);

            return match (true) {
                $decisions->contains(AgreementReviewStatus::Approved) => PlanRequestProgress::ImcApproved,
                $decisions->contains(AgreementReviewStatus::Pending) || $decisions->isEmpty() => (bool) config('jahez.agreements.imc_approval_required', true)
                    ? PlanRequestProgress::AwaitingImcReview
                    : PlanRequestProgress::Agreed,
                default => PlanRequestProgress::ImcRejected,
            };
        }

        $statuses = $request->providerRequests->map(fn (ProviderRequest $thread): ProviderRequestStatus => $thread->status);

        return match (true) {
            $statuses->contains(ProviderRequestStatus::Accepted) => PlanRequestProgress::Negotiating,
            $statuses->contains(ProviderRequestStatus::Pending) => PlanRequestProgress::Open,
            default => PlanRequestProgress::NoActiveProvider,
        };
    }

    /**
     * The latest of the requests that still occupies its plan item, or null.
     *
     * @param  Collection<int, ServiceRequest>  $requests  oldest first
     */
    public static function latestLive(Collection $requests): ?ServiceRequest
    {
        $live = null;

        foreach ($requests as $request) {
            if (self::of($request)->blocksNewRequest()) {
                $live = $request;
            }
        }

        return $live;
    }

    /**
     * What the plan shows about the request: its status, its progress, and the provider
     * whose offer was accepted. Never messages, offers or prices (OQ-39).
     *
     * @return array{id: int, status: string, progress: string, awarded_provider: array{id: int, name: string}|null, agreement_id: int|null, active_provider_count: int, provider_count: int, created_at: string|null}
     */
    public static function summary(ServiceRequest $request): array
    {
        $agreed = $request->providerRequests->first(fn (ProviderRequest $thread): bool => $thread->status === ProviderRequestStatus::Agreed);

        return [
            'id' => $request->id,
            'status' => $request->status->value,
            'progress' => self::of($request)->value,
            'awarded_provider' => $agreed?->serviceProvider === null ? null : ['id' => $agreed->serviceProvider->id, 'name' => $agreed->serviceProvider->name],
            'agreement_id' => $agreed?->agreement?->id,
            'active_provider_count' => $request->providerRequests
                ->filter(fn (ProviderRequest $thread): bool => in_array($thread->status, [ProviderRequestStatus::Pending, ProviderRequestStatus::Accepted, ProviderRequestStatus::Agreed], true))
                ->count(),
            'provider_count' => $request->providerRequests->count(),
            'created_at' => $request->created_at?->toIso8601ZuluString(),
        ];
    }
}
