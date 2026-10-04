<?php

use App\Enums\ProviderRequestStatus;
use App\Models\Agreement;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * An agreement and a draft contract between the first provider and the factory of a new
 * marketplace request, recorded directly.
 *
 * @return array{factoryMember: User, providerMembers: list<User>, agreement: Agreement, contract: Contract}
 */
function agreementPolicyScenario(): array
{
    $marketplace = marketplaceRequest(2, ProviderRequestStatus::Accepted);
    $offer = offerVersion($marketplace['threads'][0], 1, $marketplace['providerMembers'][0]);
    $agreement = Agreement::conclude($marketplace['threads'][0], $marketplace['serviceRequest'], $offer, $marketplace['factoryMember']);
    $contract = new Contract;
    $contract->agreement_id = $agreement->id;
    $contract->version = 1;
    $contract->knowledge_transfer_trainees = 2;
    $contract->knowledge_transfer_plan = 'Plan';
    $contract->drafted_by_user_id = $marketplace['factoryMember']->id;
    $contract->save();

    return [...$marketplace, 'agreement' => $agreement, 'contract' => $contract];
}

function agreementPolicyActor(string $actor, array $scenario): User
{
    return match ($actor) {
        'imc admin' => User::factory()->imcAdmin()->create(),
        'factory party' => $scenario['factoryMember'],
        'provider party' => $scenario['providerMembers'][0],
        'competing provider' => $scenario['providerMembers'][1],
        'another factory' => User::factory()->factoryMember()->create(),
    };
}

test('record decisions for each actor on an agreement and its contract', function (string $actor, string $ability, string $subject, string $expectedDecision) {
    $scenario = agreementPolicyScenario();

    $response = Gate::forUser(agreementPolicyActor($actor, $scenario))->inspect($ability, $scenario[$subject]);

    expect($response->allowed() ? 'allow' : 'deny '.($response->status() ?? 403))->toBe($expectedDecision);
})->with([
    ['factory party', 'view', 'agreement', 'allow'],
    ['provider party', 'view', 'agreement', 'allow'],
    ['imc admin', 'view', 'agreement', 'allow'],
    ['competing provider', 'view', 'agreement', 'deny 404'],
    ['another factory', 'view', 'agreement', 'deny 404'],
    ['factory party', 'draftContract', 'agreement', 'allow'],
    ['provider party', 'draftContract', 'agreement', 'allow'],
    ['imc admin', 'draftContract', 'agreement', 'deny 403'],
    ['competing provider', 'draftContract', 'agreement', 'deny 404'],
    ['factory party', 'view', 'contract', 'allow'],
    ['provider party', 'view', 'contract', 'allow'],
    ['imc admin', 'view', 'contract', 'allow'],
    ['competing provider', 'view', 'contract', 'deny 404'],
    ['another factory', 'view', 'contract', 'deny 404'],
    ['factory party', 'cancel', 'contract', 'allow'],
    ['provider party', 'cancel', 'contract', 'allow'],
    ['imc admin', 'cancel', 'contract', 'deny 403'],
    ['another factory', 'cancel', 'contract', 'deny 404'],
    ['imc admin', 'review', 'agreement', 'allow'],
    ['factory party', 'review', 'agreement', 'deny 403'],
    ['provider party', 'review', 'agreement', 'deny 403'],
    ['competing provider', 'review', 'agreement', 'deny 404'],
    ['another factory', 'review', 'agreement', 'deny 404'],
]);

test('members and IMC may list agreements and contracts; each list is scoped by the controller', function (string $actor) {
    $user = agreementPolicyActor($actor, agreementPolicyScenario());

    expect(Gate::forUser($user)->allows('viewAny', Agreement::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('viewAny', Contract::class))->toBeTrue();
})->with(['imc admin', 'factory party', 'provider party']);
