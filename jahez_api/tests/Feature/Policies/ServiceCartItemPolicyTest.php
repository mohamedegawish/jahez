<?php

use App\Models\CatalogService;
use App\Models\ServiceCartItem;
use App\Models\ServiceProvider;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Gate;

/**
 * The acting user for a matrix row, relative to the owner of the cart item.
 */
function serviceCartItemPolicyActor(string $actor, User $owner): User
{
    return match ($actor) {
        'owner' => $owner,
        'another member of the same factory' => User::factory()->factoryMember($owner->industrialFactory)->create(),
        'member of another factory' => User::factory()->factoryMember()->create(),
        'provider member' => User::factory()->providerMember()->create(),
        'imc admin' => User::factory()->imcAdmin()->create(),
    };
}

test('record decisions for each actor on a cart item', function (string $actor, string $ability, string $expectedDecision) {
    $this->seed(ReferenceDataSeeder::class);
    $owner = User::factory()->factoryMember()->create();
    $provider = ServiceProvider::factory()->approved()->offering('erp_business_applications.01')->create();
    $item = new ServiceCartItem;
    $item->user_id = $owner->id;
    $item->catalog_service_id = CatalogService::query()->where('code', 'erp_business_applications.01')->value('id');
    $item->service_provider_id = $provider->id;
    $item->save();

    $response = Gate::forUser(serviceCartItemPolicyActor($actor, $owner))->inspect($ability, $item);

    expect($response->allowed() ? 'allow' : 'deny '.($response->status() ?? 403))->toBe($expectedDecision);
})->with([
    ['owner', 'update', 'allow'],
    ['owner', 'delete', 'allow'],
    ['another member of the same factory', 'update', 'deny 404'],
    ['another member of the same factory', 'delete', 'deny 404'],
    ['member of another factory', 'update', 'deny 404'],
    ['provider member', 'delete', 'deny 404'],
    ['imc admin', 'update', 'deny 404'],
    ['imc admin', 'delete', 'deny 404'],
]);

test('only factory members have a cart', function (string $actor, bool $allowed) {
    $owner = User::factory()->factoryMember()->create();
    $user = serviceCartItemPolicyActor($actor, $owner);

    expect(Gate::forUser($user)->allows('viewAny', ServiceCartItem::class))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('create', ServiceCartItem::class))->toBe($allowed);
})->with([
    ['owner', true],
    ['member of another factory', true],
    ['provider member', false],
    ['imc admin', false],
]);
