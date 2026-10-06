<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Enums\ServiceListingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateServiceListingPackagesRequest;
use App\Http\Resources\V1\ServiceProviderResource;
use App\Models\AuditLog;
use App\Models\CatalogService;
use App\Models\ServiceListingPackage;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The packages and prices a provider lists for one of its services (ADR-027, owner
 * decisions 2026-10-06): several packages, each a name with an optional monthly price,
 * annual price and number of users. Prices are EGP and informational: the provider still
 * answers each request with offers, and nothing is paid.
 *
 * Changing them sends the listing back to IMC review (owner decision): an approved or
 * rejected listing becomes `pending` and factories no longer see it until IMC approves it
 * again. A suspended listing cannot be changed (409); a pending one stays pending. The
 * listing row is locked, so a change and an IMC decision at the same moment apply one
 * after the other. Sending the same packages again changes nothing.
 */
class ServiceListingPackageController extends Controller
{
    public function update(UpdateServiceListingPackagesRequest $request, ServiceProvider $serviceProvider, CatalogService $catalogService, #[CurrentUser] User $actor): ServiceProviderResource
    {
        $packages = $request->packages();

        DB::transaction(function () use ($serviceProvider, $catalogService, $actor, $packages): void {
            $listing = DB::table('catalog_service_service_provider')
                ->where('service_provider_id', $serviceProvider->id)
                ->where('catalog_service_id', $catalogService->id)
                ->lockForUpdate()
                ->first();

            if ($listing === null) {
                abort(404);
            }

            $previous = ServiceListingStatus::from($listing->status);

            if (! $previous->allowsPackageChange()) {
                throw new ConflictHttpException('This listing is suspended by IMC: its packages can change once IMC approves it again.');
            }

            $current = ServiceListingPackage::query()
                ->where('service_provider_id', $serviceProvider->id)
                ->where('catalog_service_id', $catalogService->id)
                ->orderBy('position')
                ->get();

            $unchanged = $current->map(fn (ServiceListingPackage $package): array => [
                'name_ar' => $package->name_ar,
                'monthly_price' => $package->monthly_price,
                'annual_price' => $package->annual_price,
                'users_count' => $package->users_count,
            ])->values()->all() === $packages;

            if ($unchanged) {
                return;
            }

            ServiceListingPackage::query()->whereKey($current->modelKeys())->delete();

            foreach ($packages as $index => $definition) {
                $package = new ServiceListingPackage;
                $package->service_provider_id = $serviceProvider->id;
                $package->catalog_service_id = $catalogService->id;
                $package->position = $index + 1;
                $package->name_ar = $definition['name_ar'];
                $package->monthly_price = $definition['monthly_price'];
                $package->annual_price = $definition['annual_price'];
                $package->users_count = $definition['users_count'];
                $package->save();
            }

            DB::table('catalog_service_service_provider')
                ->where('service_provider_id', $serviceProvider->id)
                ->where('catalog_service_id', $catalogService->id)
                ->update([
                    'status' => ServiceListingStatus::Pending->value,
                    'status_reason' => $previous === ServiceListingStatus::Pending ? $listing->status_reason : null,
                    'status_changed_at' => $previous === ServiceListingStatus::Pending ? $listing->status_changed_at : now(),
                    'submitted_at' => now(),
                ]);

            // Counts and statuses only: never a price in the audit log.
            AuditLog::record(AuditEvent::ServiceListingPackagesUpdated, $actor, $serviceProvider, [
                'service' => $catalogService->code,
                'packages' => ['from' => $current->count(), 'to' => count($packages)],
                ...($previous === ServiceListingStatus::Pending ? [] : ['status' => ['from' => $previous->value, 'to' => ServiceListingStatus::Pending->value]]),
            ]);
            MarketplaceNotifications::serviceListingPackagesChanged($serviceProvider, $catalogService);
        });

        return new ServiceProviderResource($serviceProvider->refresh()->load(ServiceProviderController::PROFILE_RELATIONS));
    }
}
