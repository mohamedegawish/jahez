<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\ServiceListingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewServiceListingRequest;
use App\Http\Resources\V1\ServiceProviderResource;
use App\Models\AuditLog;
use App\Models\CatalogService;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ServiceListingReviewController extends Controller
{
    /**
     * Record an IMC decision on one service a provider lists (ADR-021). The listing row
     * is locked, so two administrators deciding at once are applied one after the other;
     * a transition the current status does not allow is refused with 409, and a service
     * the provider does not list is 404. The provider's own approval is not touched:
     * factories see the listing only when both are approved.
     */
    public function __invoke(ReviewServiceListingRequest $request, ServiceProvider $serviceProvider, CatalogService $catalogService, #[CurrentUser] User $actor): ServiceProviderResource
    {
        $decision = $request->decision();

        DB::transaction(function () use ($request, $serviceProvider, $catalogService, $actor, $decision): void {
            $listing = DB::table('catalog_service_service_provider')
                ->where('service_provider_id', $serviceProvider->id)
                ->where('catalog_service_id', $catalogService->id)
                ->lockForUpdate()
                ->first();

            if ($listing === null) {
                abort(404);
            }

            $previous = ServiceListingStatus::from($listing->status);
            if (! $previous->canBecome($decision)) {
                abort(409, "A listing that is {$previous->value} cannot become {$decision->value}.");
            }

            DB::table('catalog_service_service_provider')
                ->where('service_provider_id', $serviceProvider->id)
                ->where('catalog_service_id', $catalogService->id)
                ->update([
                    'status' => $decision->value,
                    'status_reason' => $request->reason(),
                    'status_changed_at' => now(),
                ]);

            AuditLog::record(AuditEvent::ServiceListingReviewed, $actor, $serviceProvider, [
                'service' => $catalogService->code,
                'from' => $previous->value,
                'to' => $decision->value,
                'reason' => $request->reason(),
            ]);
            MarketplaceNotifications::serviceListingReviewed($serviceProvider, $catalogService, $decision);
        });

        return new ServiceProviderResource($serviceProvider->refresh()->load(ServiceProviderController::PROFILE_RELATIONS));
    }

    /**
     * The provider sends a rejected listing back to IMC review (ADR-022), after
     * correcting its profile, with an optional note. The same listing row returns to
     * `pending` (never approved automatically); the rejection stays in the audit
     * history. Any other status is refused with 409, so a second click while the
     * listing is pending sends nothing.
     */
    public function resubmit(Request $request, ServiceProvider $serviceProvider, CatalogService $catalogService, #[CurrentUser] User $actor): ServiceProviderResource
    {
        Gate::authorize('resubmitListing', $serviceProvider);
        $note = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:2000']])['note'] ?? null;

        DB::transaction(function () use ($serviceProvider, $catalogService, $actor, $note): void {
            $listing = DB::table('catalog_service_service_provider')
                ->where('service_provider_id', $serviceProvider->id)
                ->where('catalog_service_id', $catalogService->id)
                ->lockForUpdate()
                ->first();

            if ($listing === null) {
                abort(404);
            }

            $previous = ServiceListingStatus::from($listing->status);
            if (! $previous->canBeResubmitted()) {
                abort(409, "A listing that is {$previous->value} cannot be resubmitted; only a rejected listing can.");
            }

            DB::table('catalog_service_service_provider')
                ->where('service_provider_id', $serviceProvider->id)
                ->where('catalog_service_id', $catalogService->id)
                ->update([
                    'status' => ServiceListingStatus::Pending->value,
                    'status_reason' => null,
                    'status_changed_at' => now(),
                    'submitted_at' => now(),
                ]);

            AuditLog::record(AuditEvent::ServiceListingResubmitted, $actor, $serviceProvider, [
                'service' => $catalogService->code,
                'from' => $previous->value,
                'to' => ServiceListingStatus::Pending->value,
                'note' => $note,
            ]);
            MarketplaceNotifications::serviceListingResubmitted($serviceProvider, $catalogService);
        });

        return new ServiceProviderResource($serviceProvider->refresh()->load(ServiceProviderController::PROFILE_RELATIONS));
    }
}
