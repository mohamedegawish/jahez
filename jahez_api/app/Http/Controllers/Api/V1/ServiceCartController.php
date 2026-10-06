<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\Money;
use App\Enums\BillingPeriod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreServiceCartItemRequest;
use App\Http\Requests\Api\V1\UpdateServiceCartItemRequest;
use App\Http\Resources\V1\ServiceCartItemResource;
use App\Models\Factory;
use App\Models\ServiceCartItem;
use App\Models\ServiceListingPackage;
use App\Models\User;
use App\Readiness\ServiceEligibility;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * A factory member's cart (ADR-027, owner request 2026-10-06): provider listings the
 * member means to request, each with a package, billing period and number of users.
 * Checking out (`POST /cart/checkout`, ServiceRequestController::checkout) sends one
 * service request per service. The cart is the member's own; another account's item is
 * 404. Nothing here is a purchase: the totals are estimates from the listed prices, and
 * no payment, invoice or contract follows from the cart (OQ-15, OQ-16, OQ-17).
 */
class ServiceCartController extends Controller
{
    /** The service is no longer available to the factory's readiness level. */
    public const UNAVAILABLE_SERVICE = 'service_not_available';

    /** The provider no longer offers the service to the factory (approval, listing, sectors). */
    public const UNAVAILABLE_PROVIDER = 'provider_not_eligible';

    public function __construct(private readonly ServiceEligibility $eligibility) {}

    public function index(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('viewAny', ServiceCartItem::class);

        return $this->cart($request, $user);
    }

    /**
     * Put the listing in the cart, or replace its choice when it is already there.
     */
    public function store(StoreServiceCartItemRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $service = $request->service();
        $existing = fn (): ?ServiceCartItem => ServiceCartItem::query()
            ->where('user_id', $user->id)
            ->where('catalog_service_id', $service->id)
            ->where('service_provider_id', $request->providerId())
            ->first();

        $item = $existing();
        $created = $item === null;

        if ($item === null) {
            $item = new ServiceCartItem;
            $item->user_id = $user->id;
            $item->catalog_service_id = $service->id;
            $item->service_provider_id = $request->providerId();
        }

        $item->service_listing_package_id = $request->packageId();
        $item->billing_period = $request->billingPeriod();
        $item->users_count = $request->usersCount();

        try {
            $item->save();
        } catch (UniqueConstraintViolationException) {
            // The same listing was added at the same moment from another tab.
            $item = $existing() ?? throw new LogicException('The cart item vanished after a duplicate insert.');
            $item->service_listing_package_id = $request->packageId();
            $item->billing_period = $request->billingPeriod();
            $item->users_count = $request->usersCount();
            $item->save();
            $created = false;
        }

        return $this->cart($request, $user)->setStatusCode($created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function update(UpdateServiceCartItemRequest $request, ServiceCartItem $serviceCartItem, #[CurrentUser] User $user): JsonResponse
    {
        $serviceCartItem->service_listing_package_id = $request->packageId();
        $serviceCartItem->billing_period = $request->billingPeriod();
        $serviceCartItem->users_count = $request->usersCount();
        $serviceCartItem->save();

        return $this->cart($request, $user);
    }

    public function destroy(ServiceCartItem $serviceCartItem): Response
    {
        Gate::authorize('delete', $serviceCartItem);
        $serviceCartItem->delete();

        return response()->noContent();
    }

    public function clear(#[CurrentUser] User $user): Response
    {
        Gate::authorize('viewAny', ServiceCartItem::class);
        ServiceCartItem::query()->where('user_id', $user->id)->delete();

        return response()->noContent();
    }

    /**
     * The member's cart: every item with its current availability and the listing's
     * packages, and estimated totals per billing period over the available items with a
     * priced choice.
     */
    private function cart(Request $request, User $user): JsonResponse
    {
        $factory = Factory::query()->findOrFail((int) $user->factory_id);
        $items = ServiceCartItem::query()
            ->where('user_id', $user->id)
            ->with(['service.category', 'serviceProvider', 'package'])
            ->orderBy('id')
            ->get();

        $packages = [];
        $listed = ServiceListingPackage::query()
            ->whereIn('service_provider_id', $items->pluck('service_provider_id')->unique()->all())
            ->whereIn('catalog_service_id', $items->pluck('catalog_service_id')->unique()->all())
            ->orderBy('position')
            ->get();
        foreach ($listed as $package) {
            $packages["{$package->service_provider_id}-{$package->catalog_service_id}"][] = $package;
        }

        /** @var array<int, array{available: bool, providers: list<int>}> $eligible */
        $eligible = [];
        foreach ($items->groupBy('catalog_service_id') as $serviceId => $group) {
            $service = $group->firstOrFail()->service;
            $available = $this->eligibility->isServiceAvailable($factory, $service);
            $eligible[(int) $serviceId] = [
                'available' => $available,
                'providers' => $available
                    ? $this->eligibility->providersFor($factory, $service)->whereKey($group->pluck('service_provider_id')->all())->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()
                    : [],
            ];
        }

        $totals = [BillingPeriod::Monthly->value => 0, BillingPeriod::Annual->value => 0];
        $availableCount = 0;

        $presented = $items->map(function (ServiceCartItem $item) use ($eligible, $packages, $request, &$totals, &$availableCount): array {
            $serviceAvailable = $eligible[$item->catalog_service_id]['available'];
            $available = $serviceAvailable && in_array($item->service_provider_id, $eligible[$item->catalog_service_id]['providers'], true);
            $price = $item->price();

            if ($available) {
                $availableCount++;

                if ($price !== null && $item->billing_period !== null) {
                    $totals[$item->billing_period->value] += Money::toMinor($price);
                }
            }

            return (new ServiceCartItemResource($item))
                ->withContext(
                    $available,
                    $available ? null : ($serviceAvailable ? self::UNAVAILABLE_PROVIDER : self::UNAVAILABLE_SERVICE),
                    $packages["{$item->service_provider_id}-{$item->catalog_service_id}"] ?? [],
                )
                ->resolve($request);
        })->values()->all();

        return response()->json(['data' => [
            'items' => $presented,
            'summary' => [
                'items_count' => $items->count(),
                'services_count' => $items->pluck('catalog_service_id')->unique()->count(),
                'available_items_count' => $availableCount,
                'currency' => 'EGP',
                // Informational: from the listed prices of the available items, not a quote.
                'estimated_monthly_total' => Money::fromMinor($totals[BillingPeriod::Monthly->value]),
                'estimated_annual_total' => Money::fromMinor($totals[BillingPeriod::Annual->value]),
            ],
        ]]);
    }
}
