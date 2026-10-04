<?php

use App\Models\ProviderEvaluation;
use App\Models\ServiceProvider;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

/**
 * A complete evaluation payload: a note for every DOC §6 criterion, with the given scores.
 *
 * @param  array<string, string|int|float>|null  $scores  by criterion code; null sends no scores
 * @return array<string, mixed>
 */
function evaluationPayload(?array $scores = null, array $overrides = []): array
{
    $criteria = [];
    foreach (['technical_expertise', 'technical_cloud_model', 'knowledge_transfer', 'financial_flexibility', 'technical_support_sla'] as $code) {
        $criteria[$code] = ['note' => "Assessment of {$code}", ...($scores === null ? [] : ['score' => $scores[$code]])];
    }

    return [
        'summary' => 'Site visit and reference checks.',
        'evaluated_on' => '2026-10-01',
        'criteria' => $criteria,
        ...$overrides,
    ];
}

describe('while no scale is approved (OQ-13 interim)', function () {
    it('records a written assessment per criterion, with no score, total or pass mark', function () {
        $provider = ServiceProvider::factory()->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->postJson(route('api.v1.service-providers.evaluations.store', $provider), evaluationPayload());

        $response->assertCreated()
            ->assertJsonPath('data.scoring', 'not_configured')
            ->assertJsonPath('data.criteria_version', 1)
            ->assertJsonPath('data.weighted_total', null)
            ->assertJsonPath('data.meets_pass_mark', null)
            ->assertJsonCount(5, 'data.criteria')
            ->assertJsonPath('data.criteria.0.code', 'technical_expertise')
            ->assertJsonPath('data.criteria.0.weight_percent', '30.00')
            ->assertJsonPath('data.criteria.0.score', null)
            ->assertJsonPath('data.criteria.0.note', 'Assessment of technical_expertise');
        expect($provider->refresh()->approval_status->value)->toBe('pending');
    });

    it('refuses scores, so no unapproved number is stored', function () {
        $provider = ServiceProvider::factory()->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        config(['jahez.providers.evaluation.pass_mark' => '60']);

        $response = $this->postJson(route('api.v1.service-providers.evaluations.store', $provider), evaluationPayload([
            'technical_expertise' => 5, 'technical_cloud_model' => 5, 'knowledge_transfer' => 5, 'financial_flexibility' => 5, 'technical_support_sla' => 5,
        ]));

        $response->assertUnprocessable();
        expect($response->json('errors')['criteria.technical_expertise.score'][0])
            ->toBe('No evaluation scale is approved yet (OQ-13): record a written assessment without a score.');
        $this->assertDatabaseCount('provider_evaluations', 0);
    });
});

describe('with an approved scale', function () {
    it('computes the weighted total exactly from the DOC §6 weights and compares it with the pass mark', function (array $scores, string $total, bool $meetsPassMark) {
        config(['jahez.providers.evaluation.scale_max' => 5, 'jahez.providers.evaluation.pass_mark' => '70.75']);
        $provider = ServiceProvider::factory()->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->postJson(route('api.v1.service-providers.evaluations.store', $provider), evaluationPayload($scores));

        $response->assertCreated()
            ->assertJsonPath('data.scoring', 'scored')
            ->assertJsonPath('data.scale_max', 5)
            ->assertJsonPath('data.weighted_total', $total)
            ->assertJsonPath('data.pass_mark', '70.75')
            ->assertJsonPath('data.meets_pass_mark', $meetsPassMark);
    })->with([
        'exactly the pass mark' => [['technical_expertise' => '4.5', 'technical_cloud_model' => 3, 'knowledge_transfer' => 5, 'financial_flexibility' => '2.25', 'technical_support_sla' => 1], '70.75', true],
        'just below' => [['technical_expertise' => '4.5', 'technical_cloud_model' => 3, 'knowledge_transfer' => 5, 'financial_flexibility' => '2.25', 'technical_support_sla' => '0.99'], '70.73', false],
        'all top scores' => [['technical_expertise' => 5, 'technical_cloud_model' => 5, 'knowledge_transfer' => 5, 'financial_flexibility' => 5, 'technical_support_sla' => 5], '100.00', true],
        'all zero' => [['technical_expertise' => 0, 'technical_cloud_model' => 0, 'knowledge_transfer' => 0, 'financial_flexibility' => 0, 'technical_support_sla' => 0], '0.00', false],
    ]);

    it('rounds the weighted total half up to two decimals', function () {
        config(['jahez.providers.evaluation.scale_max' => 3]);
        $provider = ServiceProvider::factory()->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());

        $response = $this->postJson(route('api.v1.service-providers.evaluations.store', $provider), evaluationPayload([
            'technical_expertise' => 2, 'technical_cloud_model' => 2, 'knowledge_transfer' => 2, 'financial_flexibility' => 2, 'technical_support_sla' => 2,
        ]));

        $response->assertCreated()->assertJsonPath('data.weighted_total', '66.67')->assertJsonPath('data.meets_pass_mark', null);
    });

    it('rejects a missing, out-of-range or over-precise score', function (string|int|null $score) {
        config(['jahez.providers.evaluation.scale_max' => 5]);
        $provider = ServiceProvider::factory()->create();
        Sanctum::actingAs(User::factory()->imcAdmin()->create());
        $scores = ['technical_expertise' => 5, 'technical_cloud_model' => 5, 'knowledge_transfer' => 5, 'financial_flexibility' => 5, 'technical_support_sla' => $score];

        $this->postJson(route('api.v1.service-providers.evaluations.store', $provider), evaluationPayload($scores))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['criteria.technical_support_sla.score']);
    })->with([
        'missing' => [null],
        'above the scale' => [6],
        'negative' => [-1],
        'three decimals' => ['4.125'],
    ]);
});

it('requires a note for every criterion of the current matrix and nothing else', function (Closure $payload, string $field) {
    $provider = ServiceProvider::factory()->create();
    Sanctum::actingAs(User::factory()->imcAdmin()->create());

    $this->postJson(route('api.v1.service-providers.evaluations.store', $provider), $payload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
    $this->assertDatabaseCount('provider_evaluations', 0);
})->with([
    'a criterion missing' => [function () {
        $payload = evaluationPayload();
        unset($payload['criteria']['knowledge_transfer']);

        return $payload;
    }, 'criteria'],
    'an unknown criterion' => [function () {
        $payload = evaluationPayload();
        $payload['criteria']['price'] = ['note' => 'Cheap'];

        return $payload;
    }, 'criteria'],
    'an empty note' => [function () {
        $payload = evaluationPayload();
        $payload['criteria']['knowledge_transfer']['note'] = '';

        return $payload;
    }, 'criteria.knowledge_transfer.note'],
    'no summary' => [fn () => evaluationPayload(null, ['summary' => null]), 'summary'],
    'dated in the future' => [fn () => evaluationPayload(null, ['evaluated_on' => now()->addDay()->toDateString()]), 'evaluated_on'],
]);

it('lists the evaluations latest evaluation date first', function () {
    $provider = ServiceProvider::factory()->create();
    Sanctum::actingAs(User::factory()->imcAdmin()->create());
    $older = $this->postJson(route('api.v1.service-providers.evaluations.store', $provider), evaluationPayload(null, ['evaluated_on' => '2026-09-01']))->json('data.id');
    $newer = $this->postJson(route('api.v1.service-providers.evaluations.store', $provider), evaluationPayload(null, ['evaluated_on' => '2026-10-01']))->json('data.id');
    $backfilled = $this->postJson(route('api.v1.service-providers.evaluations.store', $provider), evaluationPayload(null, ['evaluated_on' => '2025-01-01']))->json('data.id');

    expect($this->getJson(route('api.v1.service-providers.evaluations.index', $provider))->json('data.*.id'))->toBe([$newer, $older, $backfilled]);
});

it('keeps evaluations internal to IMC: the provider gets 403, anyone else 404, before validation', function (Closure $makeUser, int $status) {
    $provider = ServiceProvider::factory()->create();
    Sanctum::actingAs($makeUser($provider));

    $this->getJson(route('api.v1.service-providers.evaluations.index', $provider))->assertStatus($status);
    $this->postJson(route('api.v1.service-providers.evaluations.store', $provider), ['summary' => ''])->assertStatus($status);
    $this->assertDatabaseCount('provider_evaluations', 0);
})->with([
    'its own member' => [fn (ServiceProvider $provider) => User::factory()->providerMember($provider)->create(), 403],
    'another provider' => [fn () => User::factory()->providerMember()->create(), 404],
    'a factory member' => [fn () => User::factory()->factoryMember()->create(), 404],
]);

it('refuses to change or delete a recorded evaluation', function (Closure $tamper) {
    $provider = ServiceProvider::factory()->create();
    Sanctum::actingAs(User::factory()->imcAdmin()->create());
    $id = $this->postJson(route('api.v1.service-providers.evaluations.store', $provider), evaluationPayload())->json('data.id');
    $evaluation = ProviderEvaluation::query()->findOrFail($id);

    expect(fn () => $tamper($evaluation))->toThrow(LogicException::class, 'Provider evaluations are append-only.');
})->with([
    'update' => [function (ProviderEvaluation $evaluation): void {
        $evaluation->summary = 'Rewritten';
        $evaluation->save();
    }],
    'delete' => [fn (ProviderEvaluation $evaluation) => $evaluation->delete()],
    'change a criterion' => [function (ProviderEvaluation $evaluation): void {
        $score = $evaluation->scores()->firstOrFail();
        $score->note = 'Rewritten';
        $score->save();
    }],
]);
