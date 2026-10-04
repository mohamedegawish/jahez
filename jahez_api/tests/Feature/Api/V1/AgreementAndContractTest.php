<?php

use App\Enums\ContractStatus;
use App\Models\Agreement;
use App\Models\Contract;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * A valid contract draft payload.
 *
 * @return array<string, mixed>
 */
function contractPayload(array $overrides = []): array
{
    return [
        'knowledge_transfer' => ['trainees' => 2, 'training_plan' => 'Two IMC engineers on site for the ERP rollout, weekly reviews.'],
        'notes' => 'Draft prepared from the accepted offer.',
        ...$overrides,
    ];
}

describe('agreements', function () {
    it('shows both parties the agreed terms and price, and is never binding', function (string $party) {
        ['factoryMember' => $member, 'providerMembers' => $providerMembers, 'agreement' => $agreement] = agreedMarketplace();
        Sanctum::actingAs($party === 'factory' ? $member : $providerMembers[0]);

        $response = $this->getJson(route('api.v1.agreements.show', $agreement));

        $response->assertOk()
            ->assertJsonPath('data.binding', false)
            ->assertJsonPath('data.price', ['amount' => '250000.00', 'currency' => 'EGP'])
            ->assertJsonPath('data.terms.offer_version', 1)
            ->assertJsonPath('data.contract', null)
            ->assertJsonPath('data.concluded_by.id', $member->id);
    })->with(['factory', 'provider']);

    it('shows IMC reviewers the agreed terms and price they decide on, but never the negotiation messages (ADR-020)', function () {
        ['agreement' => $agreement] = agreedMarketplace(imcApproved: false);
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->getJson(route('api.v1.agreements.show', $agreement));

        $response->assertOk()
            ->assertJsonPath('data.id', $agreement->id)
            ->assertJsonPath('data.price.amount', '250000.00')
            ->assertJsonPath('data.imc_review.status', 'pending');
        $this->getJson(route('api.v1.provider-requests.messages.index', $agreement->provider_request_id))->assertForbidden();
    });

    it('returns 404 to a competing provider and to another factory', function (Closure $makeUser) {
        ['providerMembers' => $providerMembers, 'agreement' => $agreement] = agreedMarketplace();
        Sanctum::actingAs($makeUser($providerMembers));

        $this->getJson(route('api.v1.agreements.show', $agreement))->assertNotFound();
        expect($this->getJson(route('api.v1.agreements.index'))->json('data'))->toBe([]);
    })->with([
        'competing provider' => [fn (array $providerMembers) => $providerMembers[1]],
        'another factory' => [fn () => User::factory()->factoryMember()->create()],
    ]);

    it('lists a party its own agreements', function () {
        ['factoryMember' => $member, 'providerMembers' => $providerMembers, 'agreement' => $agreement] = agreedMarketplace();

        Sanctum::actingAs($member);
        expect($this->getJson(route('api.v1.agreements.index'))->json('data.*.id'))->toBe([$agreement->id]);
        Sanctum::actingAs($providerMembers[0]);
        expect($this->getJson(route('api.v1.agreements.index'))->json('data.*.id'))->toBe([$agreement->id]);
    });

    it('is never changed or deleted', function (Closure $tamper) {
        ['agreement' => $agreement] = agreedMarketplace();

        expect(fn () => $tamper($agreement))->toThrow(LogicException::class, 'Agreements are never changed.');
    })->with([
        'update' => [function (Agreement $agreement): void {
            $agreement->price_amount = '1.00';
            $agreement->save();
        }],
        'delete' => [fn (Agreement $agreement) => $agreement->delete()],
    ]);
});

describe('contract drafts (OQ-17 interim: never binding)', function () {
    it('lets either party draft a contract with the knowledge-transfer commitment', function (string $party) {
        ['factoryMember' => $member, 'providerMembers' => $providerMembers, 'agreement' => $agreement] = agreedMarketplace();
        $drafter = $party === 'factory' ? $member : $providerMembers[0];
        Sanctum::actingAs($drafter);

        $response = $this->postJson(route('api.v1.agreements.contracts.store', $agreement), contractPayload());

        $response->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.binding', false)
            ->assertJsonPath('data.legal_status', 'draft_not_binding')
            ->assertJsonPath('data.signature.status', 'not_available')
            ->assertJsonPath('data.knowledge_transfer.trainees', 2)
            ->assertJsonPath('data.knowledge_transfer.commitment_memo.status', 'not_available')
            ->assertJsonPath('data.drafted_by.id', $drafter->id);
        expect($this->getJson(route('api.v1.agreements.show', $agreement))->json('data.contract.status'))->toBe('draft');
    })->with(['factory', 'provider']);

    it('requires at least two IMC engineers to be trained, and a training plan (DOC §6)', function (array $overrides, string $field) {
        ['agreement' => $agreement] = agreedMarketplace();

        $this->postJson(route('api.v1.agreements.contracts.store', $agreement), contractPayload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
        expect(Contract::query()->count())->toBe(0);
    })->with([
        'one trainee' => [['knowledge_transfer' => ['trainees' => 1, 'training_plan' => 'Plan']], 'knowledge_transfer.trainees'],
        'no plan' => [['knowledge_transfer' => ['trainees' => 2, 'training_plan' => '']], 'knowledge_transfer.training_plan'],
        'no commitment' => [['knowledge_transfer' => null], 'knowledge_transfer'],
        'a signature field' => [['knowledge_transfer' => ['trainees' => 2, 'training_plan' => 'Plan', 'signed' => true]], 'knowledge_transfer'],
    ]);

    it('says why one trainee is refused', function () {
        ['agreement' => $agreement] = agreedMarketplace();

        $response = $this->postJson(route('api.v1.agreements.contracts.store', $agreement), contractPayload(['knowledge_transfer' => ['trainees' => 1, 'training_plan' => 'Plan']]));

        expect($response->json('errors')['knowledge_transfer.trainees'][0])->toBe('DOC §6 requires training at least 2 IMC engineers.');
    });

    it('keeps one draft in force: a new version only after the current one is cancelled', function () {
        ['factoryMember' => $member, 'providerMembers' => $providerMembers, 'agreement' => $agreement] = agreedMarketplace();
        $first = $this->postJson(route('api.v1.agreements.contracts.store', $agreement), contractPayload())->json('data.id');

        $this->postJson(route('api.v1.agreements.contracts.store', $agreement), contractPayload())
            ->assertConflict()
            ->assertJsonPath('message', 'This agreement already has a contract draft (version 1); cancel it before drafting a new version.');

        Sanctum::actingAs($providerMembers[0]);
        $this->postJson(route('api.v1.contracts.cancel', $first), ['reason' => 'Plan needs more detail'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.status_reason', 'Plan needs more detail');
        $this->postJson(route('api.v1.contracts.cancel', $first))->assertConflict();

        $this->postJson(route('api.v1.agreements.contracts.store', $agreement), contractPayload(['knowledge_transfer' => ['trainees' => 3, 'training_plan' => 'Revised plan']]))
            ->assertCreated()
            ->assertJsonPath('data.version', 2);
        Sanctum::actingAs($member);
        expect($this->getJson(route('api.v1.contracts.index', ['filter' => ['agreement' => $agreement->id]]))->json('data.*.version'))->toBe([2, 1])
            ->and(Contract::query()->where('status', ContractStatus::Draft)->count())->toBe(1);
    });

    it('locks the agreement row while drafting, so two parties drafting at once get one draft', function () {
        ['agreement' => $agreement] = agreedMarketplace();

        $reads = lockingReads(fn () => $this->postJson(route('api.v1.agreements.contracts.store', $agreement), contractPayload())->assertCreated());

        // ADR-023: the contract-template policies are share-locked while the snapshot is taken.
        expect($reads)->toBe(['agreements:update', 'financial_policies:share']);
    });

    it('shows IMC the draft status only, and refuses IMC drafting or cancelling', function () {
        ['agreement' => $agreement] = agreedMarketplace();
        $contractId = $this->postJson(route('api.v1.agreements.contracts.store', $agreement), contractPayload())->json('data.id');
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->getJson(route('api.v1.contracts.show', $contractId));

        $response->assertOk()->assertJsonPath('data.status', 'draft');
        expect($response->json('data'))->not->toHaveKeys(['knowledge_transfer', 'notes']);
        $this->postJson(route('api.v1.agreements.contracts.store', $agreement), contractPayload())->assertForbidden();
        $this->postJson(route('api.v1.contracts.cancel', $contractId))->assertForbidden();
    });

    it('returns 404 to anyone outside the agreement, before validation', function (Closure $makeUser) {
        ['providerMembers' => $providerMembers, 'agreement' => $agreement] = agreedMarketplace();
        $contractId = $this->postJson(route('api.v1.agreements.contracts.store', $agreement), contractPayload())->json('data.id');
        Sanctum::actingAs($makeUser($providerMembers));

        $this->getJson(route('api.v1.contracts.show', $contractId))->assertNotFound();
        $this->postJson(route('api.v1.agreements.contracts.store', $agreement), ['knowledge_transfer' => null])->assertNotFound();
        $this->postJson(route('api.v1.contracts.cancel', $contractId), ['reason' => str_repeat('r', 2001)])->assertNotFound();
        expect($this->getJson(route('api.v1.contracts.index'))->json('data'))->toBe([]);
    })->with([
        'competing provider' => [fn (array $providerMembers) => $providerMembers[1]],
        'another factory' => [fn () => User::factory()->factoryMember()->create()],
    ]);
});
