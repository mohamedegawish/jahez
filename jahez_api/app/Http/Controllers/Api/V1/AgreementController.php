<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgreementReviewStatus;
use App\Enums\AuditEvent;
use App\Enums\ContractStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListAgreementsRequest;
use App\Http\Requests\Api\V1\ReviewAgreementRequest;
use App\Http\Resources\V1\AgreementResource;
use App\Models\Agreement;
use App\Models\AgreementReview;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Agreements (ADR-017): created when a factory accepts a provider's offer, never through
 * this API, and never changed. Not a contract, an invoice or a payment. IMC reviews each
 * one (ADR-020) before a contract draft or an invoice can be made for it.
 */
class AgreementController extends Controller
{
    public const RELATIONS = ['industrialFactory', 'serviceProvider', 'service', 'offer', 'concludedBy', 'activeContract', 'review.reviewedBy', 'serviceRequest'];

    /**
     * A party's agreements, newest first; every agreement for IMC administrators.
     */
    public function index(ListAgreementsRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        $search = $request->filled('search') ? '%'.addcslashes($request->string('search')->toString(), '%_\\').'%' : null;
        $reviewStatus = AgreementReviewStatus::tryFrom($request->string('filter.review_status')->toString());

        $agreements = Agreement::query()
            ->when($user->factory_id !== null, fn (Builder $query) => $query->where('factory_id', $user->factory_id))
            ->when($user->service_provider_id !== null, fn (Builder $query) => $query->where('service_provider_id', $user->service_provider_id))
            ->when($reviewStatus === AgreementReviewStatus::Pending, fn (Builder $query) => $query->whereDoesntHave('review'))
            ->when($reviewStatus !== null && $reviewStatus !== AgreementReviewStatus::Pending, fn (Builder $query) => $query->whereHas('review', fn (Builder $reviews) => $reviews->where('decision', $reviewStatus)))
            ->when($request->input('filter.contract_status') === 'draft', fn (Builder $query) => $query->whereHas('activeContract'))
            ->when($request->input('filter.contract_status') === 'none', fn (Builder $query) => $query->whereDoesntHave('contracts', fn (Builder $contracts) => $contracts->where('status', ContractStatus::Draft)))
            ->when($search, fn (Builder $query, string $search) => $query->where(fn (Builder $any) => $any
                ->whereHas('industrialFactory', fn (Builder $factories) => $factories->whereLike('name', $search))
                ->orWhereHas('serviceProvider', fn (Builder $providers) => $providers->whereLike('name', $search))
                ->orWhereHas('service', fn (Builder $services) => $services->whereLike('name_ar', $search))))
            ->with(self::RELATIONS)
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return AgreementResource::collection($agreements);
    }

    public function show(Agreement $agreement): AgreementResource
    {
        Gate::authorize('view', $agreement);

        return new AgreementResource($agreement->load(self::RELATIONS));
    }

    /**
     * IMC approves or rejects the agreement (ADR-020). The decision is final: a second
     * decision, concurrent or later, gets 409. Approval allows a contract draft and an
     * invoice; it does not make anything binding (OQ-17) and charges nothing.
     */
    public function review(ReviewAgreementRequest $request, Agreement $agreement, #[CurrentUser] User $user): AgreementResource
    {
        DB::transaction(function () use ($request, $agreement, $user): void {
            $locked = Agreement::query()->lockForUpdate()->findOrFail($agreement->id);

            if ($locked->review()->exists()) {
                throw new ConflictHttpException('IMC has already decided on this agreement.');
            }

            $review = new AgreementReview;
            $review->agreement_id = $locked->id;
            $review->decision = $request->decision();
            $review->reason = $request->reason();
            $review->reviewed_by_user_id = $user->id;
            $review->decided_at = now();
            $review->save();

            AuditLog::record(AuditEvent::AgreementReviewed, $user, $locked, [
                'decision' => $review->decision->value,
                'reason' => $review->reason,
            ]);

            MarketplaceNotifications::agreementReviewed($locked, $review->decision === AgreementReviewStatus::Approved);
        });

        return $this->show($agreement->refresh());
    }
}
