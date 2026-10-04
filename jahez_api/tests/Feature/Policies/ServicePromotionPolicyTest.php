<?php

use App\Models\ServicePromotion;
use App\Models\ServiceProvider;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Gate;

test('only IMC administrators manage promotions; members are told a promotion does not exist', function (string $actor, bool $manage, string $updateDecision) {
    $this->seed(ReferenceDataSeeder::class);
    $provider = ServiceProvider::factory()->approved()->offering('erp_business_applications.01')->create();
    $promotion = new ServicePromotion;
    $promotion->service_provider_id = $provider->id;
    $promotion->catalog_service_id = $provider->services()->firstOrFail()->id;
    $promotion->starts_at = now();
    $promotion->save();

    $user = match ($actor) {
        'imc admin' => User::factory()->imcAdmin()->create(),
        'factory member' => User::factory()->factoryMember()->create(),
        'the promoted provider' => User::factory()->providerMember($provider)->create(),
    };
    $update = Gate::forUser($user)->inspect('update', $promotion);

    expect(Gate::forUser($user)->allows('viewAny', ServicePromotion::class))->toBe($manage)
        ->and(Gate::forUser($user)->allows('create', ServicePromotion::class))->toBe($manage)
        ->and($update->allowed() ? 'allow' : 'deny '.($update->status() ?? 403))->toBe($updateDecision);
})->with([
    ['imc admin', true, 'allow'],
    ['factory member', false, 'deny 404'],
    ['the promoted provider', false, 'deny 404'],
]);
