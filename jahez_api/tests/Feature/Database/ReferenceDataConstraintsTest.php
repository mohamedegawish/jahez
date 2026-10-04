<?php

use App\Models\EvaluationCriterion;
use App\Models\MaturityTier;
use App\Models\PathwayLevel;
use App\Models\PathwayScopeItem;
use App\Models\Sector;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Attributes for an evaluation criterion; only version and code matter to these tests.
 *
 * @return array<string, int|string>
 */
function evaluationCriterionAttributes(int $version, string $code): array
{
    return [
        'version' => $version,
        'code' => $code,
        'name_ar' => 'معيار',
        'sub_elements_ar' => 'عناصر',
        'weight_percent' => '10.00',
        'verification_ar' => 'تحقق',
        'sort_order' => 1,
    ];
}

it('rejects a duplicate sector code', function () {
    Sector::query()->create(['code' => 'food', 'name_ar' => 'الصناعات الغذائية', 'sort_order' => 1]);

    expect(fn () => Sector::query()->create(['code' => 'food', 'name_ar' => 'قطاع آخر', 'sort_order' => 2]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('prevents deleting a pathway level that tiers and scope items depend on', function () {
    $this->seed(ReferenceDataSeeder::class);
    $basicLevel = PathwayLevel::query()->where('code', 'basic_dx')->firstOrFail();

    expect(fn () => $basicLevel->delete())->toThrow(QueryException::class);

    $this->assertModelExists($basicLevel);
});

it('rejects two scope items in the same position of a level', function () {
    $this->seed(ReferenceDataSeeder::class);
    $basicLevel = PathwayLevel::query()->where('code', 'basic_dx')->firstOrFail();

    expect(fn () => PathwayScopeItem::query()->create([
        'pathway_level_id' => $basicLevel->id,
        'text_ar' => 'بند مكرر',
        'sort_order' => 1,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('rejects the same criterion code twice within one version', function () {
    EvaluationCriterion::query()->create(evaluationCriterionAttributes(1, 'knowledge_transfer'));

    expect(fn () => EvaluationCriterion::query()->create(evaluationCriterionAttributes(1, 'knowledge_transfer')))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows a criterion code to be reused by a new methodology version', function () {
    EvaluationCriterion::query()->create(evaluationCriterionAttributes(1, 'knowledge_transfer'));

    EvaluationCriterion::query()->create(evaluationCriterionAttributes(2, 'knowledge_transfer'));

    expect(EvaluationCriterion::query()->where('code', 'knowledge_transfer')->pluck('version')->sort()->values()->all())
        ->toBe([1, 2]);
});

it('does not allow tier score thresholds to be mass assigned before they are approved', function () {
    $this->seed(ReferenceDataSeeder::class);
    $foundationTier = MaturityTier::query()->where('code', 'foundation')->firstOrFail();

    expect(fn () => $foundationTier->update(['score_min' => '0.00', 'score_max' => '25.00']))
        ->toThrow(MassAssignmentException::class);

    expect($foundationTier->fresh()?->score_min)->toBeNull();
});
