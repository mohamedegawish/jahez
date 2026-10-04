<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\ProviderRequestStatus;
use App\Enums\ServiceRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOfferRequest;
use App\Http\Resources\V1\OfferResource;
use App\Models\Agreement;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\ProviderRequest;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Formal offers on one provider request, in versions (ADR-015). Accepting an offer
 * creates no contract, invoice or payment (OQ-15, OQ-16, OQ-17). The audit log records
 * offer versions and acceptance, never the price or terms, which IMC administrators may
 * not see (PROPOSED, OQ-39).
 */
class OfferController extends Controller
{
    /**
     * Every version, newest first, each with its state.
     */
    public function index(ProviderRequest $providerRequest): JsonResponse
    {
        Gate::authorize('negotiate', $providerRequest);

        $offers = $providerRequest->offers()->with('author')->orderByDesc('version')->get();
        $latestVersion = (int) $offers->max('version');

        return response()->json([
            'data' => $offers->map(fn (Offer $offer): OfferResource => new OfferResource($offer, $this->stateOf($offer, $latestVersion, $providerRequest))),
        ]);
    }

    /**
     * Submit the next version. `based_on_version` must be the latest existing version (or
     * null for the first), so a stale form or a retried request gets 409 instead of
     * creating a version the provider did not mean to send. Only the thread is locked: an
     * offer version changes nothing on the parent request.
     */
    public function store(StoreOfferRequest $request, ProviderRequest $providerRequest, #[CurrentUser] User $user): JsonResponse
    {
        $offer = DB::transaction(function () use ($request, $providerRequest, $user): Offer {
            $locked = ProviderRequest::lockThread($providerRequest->id);

            if ($locked->status !== ProviderRequestStatus::Accepted) {
                throw new ConflictHttpException("Offers can be submitted only while the negotiation is open; this provider request is {$locked->status->value}.");
            }

            $locked->ensureProviderApproved();

            $latestVersion = (int) $locked->offers()->max('version');
            $basedOnVersion = $request->basedOnVersion();

            if ($basedOnVersion !== ($latestVersion === 0 ? null : $latestVersion)) {
                throw new ConflictHttpException($latestVersion === 0
                    ? 'There is no earlier offer; send based_on_version as null.'
                    : "The latest offer is version {$latestVersion}; send it as based_on_version to revise it.");
            }

            $offer = new Offer;
            $offer->provider_request_id = $locked->id;
            $offer->version = $latestVersion + 1;
            $offer->scope = $request->string('scope')->toString();
            $offer->deliverables = $request->string('deliverables')->toString();
            $offer->duration_days = $request->integer('duration_days');
            $offer->price_amount = (string) $request->input('price.amount');
            $offer->currency = Offer::CURRENCY;
            $offer->valid_until = $request->date('valid_until');
            $offer->author_user_id = $user->id;
            $offer->save();

            AuditLog::record(AuditEvent::OfferSubmitted, $user, $locked, ['version' => $offer->version]);
            MarketplaceNotifications::offerSubmitted($locked, $offer);

            return $offer;
        });

        return (new OfferResource($offer->refresh()->load('author')))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /**
     * The factory accepts the latest offer of a negotiation. That thread becomes
     * `agreed` and the request `awarded`. With the single-award rule (the PROPOSED
     * default, config jahez.marketplace.single_award, OQ-38) every other open thread is
     * closed and no other offer can be accepted; without it the other threads continue
     * and their offers can be accepted too. An older version, an expired offer, a request
     * that can take no further acceptance, a thread that is not negotiating, or a
     * provider IMC has suspended gets 409.
     */
    public function accept(ProviderRequest $providerRequest, Offer $offer, #[CurrentUser] User $user): OfferResource
    {
        Gate::authorize('acceptOffer', $providerRequest);

        DB::transaction(function () use ($providerRequest, $offer, $user): void {
            [$serviceRequest, $locked] = ProviderRequest::lockWithServiceRequest($providerRequest->id);

            $singleAward = (bool) config('jahez.marketplace.single_award', true);
            $acceptsMore = $serviceRequest->isOpen() || (! $singleAward && $serviceRequest->status === ServiceRequestStatus::Awarded);

            if (! $acceptsMore) {
                throw new ConflictHttpException("This service request is {$serviceRequest->status->value}; no further offer can be accepted.");
            }

            $latestVersion = (int) $locked->offers()->max('version');

            if ($offer->version !== $latestVersion) {
                throw new ConflictHttpException("Only the latest offer (version {$latestVersion}) can be accepted.");
            }

            if ($offer->hasExpired()) {
                throw new ConflictHttpException("This offer was valid until {$offer->valid_until?->toDateString()} and can no longer be accepted.");
            }

            if ($locked->status->canBecome(ProviderRequestStatus::Agreed)) {
                $locked->ensureProviderApproved();
            }

            $locked->agreed_offer_id = $offer->id;
            $locked->moveTo(ProviderRequestStatus::Agreed, null, $user);

            if ($serviceRequest->isOpen()) {
                $serviceRequest->moveTo(ServiceRequestStatus::Awarded);
            }

            $closedIds = $singleAward
                ? ProviderRequest::closeOpenThreadsOf($serviceRequest->id, 'request_awarded', exceptId: $locked->id, actor: $user)
                : [];

            AuditLog::record(AuditEvent::OfferAccepted, $user, $locked, [
                'version' => $offer->version,
                'closed_provider_request_ids' => $closedIds,
            ]);

            $agreement = Agreement::conclude($locked, $serviceRequest, $offer, $user);
            AuditLog::record(AuditEvent::AgreementConcluded, $user, $agreement, [
                'provider_request_id' => $locked->id,
                'offer_version' => $offer->version,
            ]);

            MarketplaceNotifications::offerAccepted($locked, $agreement);
            MarketplaceNotifications::threadsClosed($serviceRequest, $closedIds, 'request_awarded');
        });

        return new OfferResource($offer->load('author'), OfferResource::STATE_ACCEPTED);
    }

    /**
     * The latest version is current only while the negotiation is open; once the thread
     * has ended without an agreement it has lapsed, and once its validity date has passed
     * it has expired.
     */
    private function stateOf(Offer $offer, int $latestVersion, ProviderRequest $providerRequest): string
    {
        return match (true) {
            $offer->id === $providerRequest->agreed_offer_id => OfferResource::STATE_ACCEPTED,
            $offer->version !== $latestVersion => OfferResource::STATE_SUPERSEDED,
            $providerRequest->status !== ProviderRequestStatus::Accepted => OfferResource::STATE_LAPSED,
            $offer->hasExpired() => OfferResource::STATE_EXPIRED,
            default => OfferResource::STATE_CURRENT,
        };
    }
}
