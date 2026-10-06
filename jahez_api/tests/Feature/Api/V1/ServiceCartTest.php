<?php

use App\Enums\AuditEvent;
use App\Enums\FactoryApprovalStatus;
use App\Enums\NotificationEvent;
use App\Http\Requests\Api\V1\CheckoutServiceCartRequest;
use App\Http\Requests\Api\V1\StoreServiceCartItemRequest;
use App\Models\AuditLog;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\ProviderRequest;
use App\Models\ServiceCartItem;
use App\Models\ServiceListingPackage;
use App\Models\ServiceProvider;
use App\Models\ServiceRequest;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * The factory cart (ADR-027, owner decisions 2026-10-06): a member puts provider listings
 * in a cart with a package, a billing period and a number of users, then sends one service
 * request per service to the providers chosen for it. Each thread keeps a copy of the
 * choice. Totals are estimates from the listed prices; nothing is paid.
 */
const CART_ERP = 'erp_business_applications.01';
const CART_MES = 'automation_ot.01';

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->factory = factoryWithEveryService('food');
    $this->member = User::factory()->factoryMember($this->factory)->create();
    $this->providerA = ServiceProvider::factory()->approved()->inSectors('food')->offering(CART_ERP, CART_MES)->create(['name' => 'Provider A']);
    $this->providerB = ServiceProvider::factory()->approved()->inSectors('food')->offering(CART_ERP)->create(['name' => 'Provider B']);
    $this->providerAMember = User::factory()->providerMember($this->providerA)->create();
    $this->aBasic = cartPackage($this->providerA, CART_ERP, 1, 'أساسية', '1500.00', '15000.00', 10);
    $this->aPro = cartPackage($this->providerA, CART_ERP, 2, 'احترافية', null, '40000.00', 50);
    $this->bOnly = cartPackage($this->providerB, CART_ERP, 1, 'سنوية', null, '12000.00', null);
    Sanctum::actingAs($this->member);
});

/**
 * A package stored directly, as if IMC had approved the listing with it.
 */
function cartPackage(ServiceProvider $provider, string $code, int $position, string $name, ?string $monthly, ?string $annual, ?int $users): ServiceListingPackage
{
    $package = new ServiceListingPackage;
    $package->service_provider_id = $provider->id;
    $package->catalog_service_id = CatalogService::query()->where('code', $code)->value('id');
    $package->position = $position;
    $package->name_ar = $name;
    $package->monthly_price = $monthly;
    $package->annual_price = $annual;
    $package->users_count = $users;
    $package->save();

    return $package;
}

/**
 * @param  array<string, mixed>  $choice
 */
function addToCart(ServiceProvider $provider, string $code, array $choice = []): TestResponse
{
    return test()->postJson(route('api.v1.cart.items.store'), ['service' => $code, 'provider_id' => $provider->id, ...$choice]);
}

/**
 * @return array<string, mixed>
 */
function cartCheckoutEntry(string $code): array
{
    return ['service' => $code, 'title' => "طلب {$code}", 'need' => 'نحتاج الخدمة لخط الإنتاج الرئيسي.'];
}

it('puts listings in the cart with a package, period and users, and estimates the totals from the listed prices', function () {
    addToCart($this->providerA, CART_ERP, ['package_id' => $this->aBasic->id, 'billing_period' => 'monthly', 'users_count' => 8])
        ->assertCreated()
        ->assertJsonPath('data.items.0.provider.name', 'Provider A')
        ->assertJsonPath('data.items.0.package.name_ar', 'أساسية')
        ->assertJsonPath('data.items.0.billing_period', 'monthly')
        ->assertJsonPath('data.items.0.users_count', 8)
        ->assertJsonPath('data.items.0.price', '1500.00')
        ->assertJsonPath('data.items.0.available', true)
        ->assertJsonCount(2, 'data.items.0.packages');
    addToCart($this->providerB, CART_ERP, ['package_id' => $this->bOnly->id, 'billing_period' => 'annual'])->assertCreated();
    addToCart($this->providerA, CART_MES)->assertCreated()->assertJsonPath('data.items.2.price', null);

    $this->getJson(route('api.v1.cart.index'))
        ->assertOk()
        ->assertJsonCount(3, 'data.items')
        ->assertJsonPath('data.summary.items_count', 3)
        ->assertJsonPath('data.summary.services_count', 2)
        ->assertJsonPath('data.summary.available_items_count', 3)
        ->assertJsonPath('data.summary.currency', 'EGP')
        ->assertJsonPath('data.summary.estimated_monthly_total', '1500.00')
        ->assertJsonPath('data.summary.estimated_annual_total', '12000.00');

    // Adding a listing already in the cart replaces its choice.
    addToCart($this->providerA, CART_ERP, ['package_id' => $this->aPro->id, 'billing_period' => 'annual'])
        ->assertOk()
        ->assertJsonCount(3, 'data.items')
        ->assertJsonPath('data.items.0.package.name_ar', 'احترافية')
        ->assertJsonPath('data.items.0.users_count', null)
        ->assertJsonPath('data.summary.estimated_monthly_total', '0.00')
        ->assertJsonPath('data.summary.estimated_annual_total', '52000.00');
    expect(ServiceCartItem::query()->count())->toBe(3);
});

it('refuses a provider, package or period the listing does not allow', function (Closure $choice, string $field, ?string $message) {
    [$provider, $code, $extra] = $choice($this);

    $response = addToCart($provider, $code, $extra)->assertUnprocessable()->assertJsonValidationErrors($field);
    if ($message !== null) {
        $response->assertJsonPath("errors.{$field}.0", $message);
    }
    expect(ServiceCartItem::query()->count())->toBe(0);
})->with([
    'provider outside the factory\'s sectors' => [fn ($test) => [ServiceProvider::factory()->approved()->inSectors('chemical')->offering(CART_ERP)->create(), CART_ERP, []], 'provider_id', StoreServiceCartItemRequest::PROVIDER_NOT_ELIGIBLE],
    'provider that does not offer the service' => [fn ($test) => [$test->providerB, CART_MES, []], 'provider_id', StoreServiceCartItemRequest::PROVIDER_NOT_ELIGIBLE],
    'package of another listing' => [fn ($test) => [$test->providerA, CART_ERP, ['package_id' => $test->bOnly->id]], 'package_id', StoreServiceCartItemRequest::PACKAGE_OF_ANOTHER_LISTING],
    'period without a package' => [fn ($test) => [$test->providerA, CART_ERP, ['billing_period' => 'monthly']], 'billing_period', StoreServiceCartItemRequest::PERIOD_NEEDS_PACKAGE],
    'period the package has no price for' => [fn ($test) => [$test->providerA, CART_ERP, ['package_id' => $test->aPro->id, 'billing_period' => 'monthly']], 'billing_period', StoreServiceCartItemRequest::PERIOD_WITHOUT_PRICE],
    'unknown period' => [fn ($test) => [$test->providerA, CART_ERP, ['package_id' => $test->aBasic->id, 'billing_period' => 'weekly']], 'billing_period', null],
    'no users' => [fn ($test) => [$test->providerA, CART_ERP, ['users_count' => 0]], 'users_count', null],
    'unknown service' => [fn ($test) => [$test->providerA, 'no_such.01', []], 'service', null],
]);

it('changes, removes and clears items of the member\'s own cart only', function () {
    $itemId = addToCart($this->providerA, CART_ERP, ['package_id' => $this->aBasic->id])->json('data.items.0.id');
    addToCart($this->providerB, CART_ERP)->assertCreated();

    $this->patchJson(route('api.v1.cart.items.update', $itemId), ['billing_period' => 'annual', 'users_count' => 12])
        ->assertOk()
        ->assertJsonPath('data.items.0.package.name_ar', 'أساسية')
        ->assertJsonPath('data.items.0.billing_period', 'annual')
        ->assertJsonPath('data.items.0.price', '15000.00');
    $this->patchJson(route('api.v1.cart.items.update', $itemId), ['package_id' => $this->bOnly->id])->assertUnprocessable()->assertJsonValidationErrors('package_id');
    $this->patchJson(route('api.v1.cart.items.update', $itemId), ['package_id' => null])->assertUnprocessable()->assertJsonValidationErrors('billing_period');

    Sanctum::actingAs(User::factory()->factoryMember($this->factory)->create());
    $this->getJson(route('api.v1.cart.index'))->assertOk()->assertJsonCount(0, 'data.items');
    $this->patchJson(route('api.v1.cart.items.update', $itemId), ['users_count' => 3])->assertNotFound();
    $this->deleteJson(route('api.v1.cart.items.destroy', $itemId))->assertNotFound();

    Sanctum::actingAs($this->member);
    $this->deleteJson(route('api.v1.cart.items.destroy', $itemId))->assertNoContent();
    expect(ServiceCartItem::query()->count())->toBe(1);
    $this->deleteJson(route('api.v1.cart.clear'))->assertNoContent();
    expect(ServiceCartItem::query()->count())->toBe(0);
});

it('gives a cart to factory members only', function (Closure $makeUser) {
    Sanctum::actingAs($makeUser());

    $this->getJson(route('api.v1.cart.index'))->assertForbidden();
    addToCart($this->providerA, CART_ERP)->assertForbidden();
    $this->postJson(route('api.v1.cart.checkout'), ['requests' => [cartCheckoutEntry(CART_ERP)]])->assertForbidden();
})->with([
    'IMC administrator' => [fn () => User::factory()->imcAdmin()->create()],
    'provider member' => [fn () => User::factory()->providerMember(ServiceProvider::factory()->approved()->create())->create()],
]);

it('shows an item as unavailable once the provider changes its packages, and clears the package chosen', function () {
    addToCart($this->providerA, CART_ERP, ['package_id' => $this->aBasic->id, 'billing_period' => 'monthly'])->assertCreated();

    Sanctum::actingAs($this->providerAMember);
    $this->putJson(route('api.v1.service-providers.services.packages.update', [$this->providerA, CatalogService::query()->where('code', CART_ERP)->value('id')]), [
        'packages' => [['name_ar' => 'جديدة', 'monthly_price' => 2000]],
    ])->assertOk();

    Sanctum::actingAs($this->member->fresh());
    $this->getJson(route('api.v1.cart.index'))
        ->assertOk()
        ->assertJsonPath('data.items.0.available', false)
        ->assertJsonPath('data.items.0.unavailable_reason', 'provider_not_eligible')
        ->assertJsonPath('data.items.0.package', null)
        ->assertJsonPath('data.summary.available_items_count', 0)
        ->assertJsonPath('data.summary.estimated_monthly_total', '0.00');
    $this->postJson(route('api.v1.cart.checkout'), ['requests' => [cartCheckoutEntry(CART_ERP)]])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['requests.0.service' => [CheckoutServiceCartRequest::INELIGIBLE_ITEMS]]);
});

it('sends one request per service to the providers in the cart, each thread keeping the choice made for it', function () {
    addToCart($this->providerA, CART_ERP, ['package_id' => $this->aBasic->id, 'billing_period' => 'monthly', 'users_count' => 8])->assertCreated();
    addToCart($this->providerB, CART_ERP, ['package_id' => $this->bOnly->id, 'billing_period' => 'annual'])->assertCreated();
    addToCart($this->providerA, CART_MES)->assertCreated();

    $response = $this->postJson(route('api.v1.cart.checkout'), ['requests' => [cartCheckoutEntry(CART_ERP)]])
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.service.code', CART_ERP)
        ->assertJsonPath('data.0.title', 'طلب '.CART_ERP)
        ->assertJsonCount(2, 'data.0.provider_requests');

    $request = ServiceRequest::query()->sole();
    $threadA = ProviderRequest::query()->where('service_provider_id', $this->providerA->id)->sole();
    expect($response->json('data.0.id'))->toBe($request->id)
        ->and($request->factory_id)->toBe($this->factory->id)
        ->and($threadA->selection)->toEqual([
            'package' => ['id' => $this->aBasic->id, 'name_ar' => 'أساسية', 'monthly_price' => '1500.00', 'annual_price' => '15000.00', 'users_count' => 10],
            'billing_period' => 'monthly',
            'users_count' => 8,
        ])
        ->and(ProviderRequest::query()->where('service_provider_id', $this->providerB->id)->sole()->selection['package']['annual_price'] ?? null)->toBe('12000.00')
        ->and(AuditLog::query()->where('event', AuditEvent::ServiceRequestCreated->value)->sole()->metadata)->toEqual([
            'service' => CART_ERP,
            'provider_ids' => [$this->providerA->id, $this->providerB->id],
            'source' => 'cart',
        ])
        ->and($this->providerAMember->notifications()->where('data->event', NotificationEvent::RequestReceived->value)->count())->toBe(1)
        // The service sent left the cart; the other stays.
        ->and(ServiceCartItem::query()->pluck('service_provider_id')->all())->toBe([$this->providerA->id]);

    // A later price change never rewrites the copy kept on the thread.
    ServiceListingPackage::query()->whereKey($this->aBasic->id)->update(['monthly_price' => '9999.00']);
    expect($threadA->fresh()->selection['package']['monthly_price'] ?? null)->toBe('1500.00');

    // Sending the same service again finds nothing in the cart.
    $this->postJson(route('api.v1.cart.checkout'), ['requests' => [cartCheckoutEntry(CART_ERP)]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['requests.0.service' => CheckoutServiceCartRequest::NOT_IN_CART]);
    $this->postJson(route('api.v1.cart.checkout'), ['requests' => [cartCheckoutEntry(CART_MES)]])->assertCreated();
    expect(ServiceRequest::query()->count())->toBe(2)->and(ServiceCartItem::query()->count())->toBe(0);
});

it('locks the factory, then the cart, then per service the level row and the providers', function () {
    addToCart($this->providerA, CART_ERP)->assertCreated();
    addToCart($this->providerA, CART_MES)->assertCreated();

    $reads = lockingReads(fn () => $this->postJson(route('api.v1.cart.checkout'), ['requests' => [cartCheckoutEntry(CART_MES), cartCheckoutEntry(CART_ERP)]])->assertCreated());

    expect($reads)->toBe([
        'factories:share',
        'service_cart_items:update',
        'readiness_level_services:share',
        'service_providers:share',
        'readiness_level_services:share',
        'service_providers:share',
    ]);
});

it('sends every service of a checkout or none of them', function () {
    addToCart($this->providerA, CART_ERP)->assertCreated();
    addToCart($this->providerA, CART_MES)->assertCreated();
    DB::table('catalog_service_service_provider')
        ->where('service_provider_id', $this->providerA->id)
        ->where('catalog_service_id', CatalogService::query()->where('code', CART_MES)->value('id'))
        ->update(['status' => 'suspended']);

    $this->postJson(route('api.v1.cart.checkout'), ['requests' => [cartCheckoutEntry(CART_ERP), cartCheckoutEntry(CART_MES)]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('requests.1.service');

    expect(ServiceRequest::query()->count())->toBe(0)->and(ServiceCartItem::query()->count())->toBe(2);
});

it('needs an approved factory and a title and need for each service', function () {
    addToCart($this->providerA, CART_ERP)->assertCreated();

    $this->postJson(route('api.v1.cart.checkout'), ['requests' => [['service' => CART_ERP]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['requests.0.title', 'requests.0.need']);
    $this->postJson(route('api.v1.cart.checkout'), ['requests' => []])->assertUnprocessable()->assertJsonValidationErrors('requests');

    Factory::query()->whereKey($this->factory->id)->update(['approval_status' => FactoryApprovalStatus::Suspended->value]);
    $this->postJson(route('api.v1.cart.checkout'), ['requests' => [cartCheckoutEntry(CART_ERP)]])->assertConflict();

    expect(ServiceRequest::query()->count())->toBe(0)->and(ServiceCartItem::query()->count())->toBe(1);
});

it('shows the cart choice to the factory and the provider of the thread, never to IMC', function () {
    addToCart($this->providerA, CART_ERP, ['package_id' => $this->aBasic->id, 'billing_period' => 'annual'])->assertCreated();
    $requestId = $this->postJson(route('api.v1.cart.checkout'), ['requests' => [cartCheckoutEntry(CART_ERP)]])->assertCreated()->json('data.0.id');
    $threadId = ProviderRequest::query()->sole()->id;

    $this->getJson(route('api.v1.service-requests.show', $requestId))
        ->assertOk()
        ->assertJsonPath('data.provider_requests.0.selection.billing_period', 'annual')
        ->assertJsonPath('data.provider_requests.0.selection.package.name_ar', 'أساسية');

    Sanctum::actingAs($this->providerAMember);
    $this->getJson(route('api.v1.provider-requests.show', $threadId))
        ->assertOk()
        ->assertJsonPath('data.selection.package.annual_price', '15000.00');

    Sanctum::actingAs(User::factory()->imcAdmin()->create());
    $this->getJson(route('api.v1.service-requests.show', $requestId))
        ->assertOk()
        ->assertJsonMissingPath('data.provider_requests.0.selection');
});
