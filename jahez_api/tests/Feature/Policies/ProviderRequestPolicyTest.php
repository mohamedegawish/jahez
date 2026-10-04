<?php

use App\Models\ProviderRequest;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('record decisions for each actor on a provider request', function (string $actor, string $ability, string $expectedDecision) {
    ['factoryMember' => $factoryMember, 'threads' => $threads, 'providerMembers' => $providerMembers] = marketplaceRequest(2);
    $user = match ($actor) {
        'imc admin' => User::factory()->imcAdmin()->create(),
        'requesting factory member' => $factoryMember,
        'the provider' => $providerMembers[0],
        'competing provider on the same request' => $providerMembers[1],
        'member of another factory' => User::factory()->factoryMember()->create(),
        'unrelated provider' => User::factory()->providerMember(ServiceProvider::factory()->approved()->create())->create(),
    };

    $response = Gate::forUser($user)->inspect($ability, $threads[0]);

    expect($response->allowed() ? 'allow' : 'deny '.($response->status() ?? 403))->toBe($expectedDecision);
})->with([
    ['imc admin', 'view', 'allow'],
    ['requesting factory member', 'view', 'allow'],
    ['the provider', 'view', 'allow'],
    ['competing provider on the same request', 'view', 'deny 404'],
    ['member of another factory', 'view', 'deny 404'],
    ['unrelated provider', 'view', 'deny 404'],
    ['the provider', 'respond', 'allow'],
    ['requesting factory member', 'respond', 'deny 403'],
    ['imc admin', 'respond', 'deny 403'],
    ['competing provider on the same request', 'respond', 'deny 404'],
    ['requesting factory member', 'withdraw', 'allow'],
    ['the provider', 'withdraw', 'deny 403'],
    ['imc admin', 'withdraw', 'deny 403'],
    ['member of another factory', 'withdraw', 'deny 404'],
    ['requesting factory member', 'negotiate', 'allow'],
    ['the provider', 'negotiate', 'allow'],
    ['imc admin', 'negotiate', 'deny 403'],
    ['competing provider on the same request', 'negotiate', 'deny 404'],
    ['unrelated provider', 'negotiate', 'deny 404'],
    ['the provider', 'submitOffer', 'allow'],
    ['requesting factory member', 'submitOffer', 'deny 403'],
    ['imc admin', 'submitOffer', 'deny 403'],
    ['competing provider on the same request', 'submitOffer', 'deny 404'],
    ['requesting factory member', 'acceptOffer', 'allow'],
    ['the provider', 'acceptOffer', 'deny 403'],
    ['imc admin', 'acceptOffer', 'deny 403'],
    ['member of another factory', 'acceptOffer', 'deny 404'],
]);

test('any organization member or IMC administrator may list provider requests, each scoped to their own', function () {
    ['factoryMember' => $factoryMember, 'providerMembers' => $providerMembers] = marketplaceRequest(1);

    foreach ([$factoryMember, $providerMembers[0], User::factory()->imcAdmin()->create()] as $user) {
        expect(Gate::forUser($user)->allows('viewAny', ProviderRequest::class))->toBeTrue();
    }
});
