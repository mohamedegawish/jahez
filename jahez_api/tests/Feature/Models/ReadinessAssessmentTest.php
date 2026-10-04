<?php

use App\Models\Factory;
use App\Models\ReadinessAssessment;
use App\Models\ReadinessAssessmentAnswer;
use App\Models\ReadinessCategory;
use App\Models\ReadinessQuestionnaire;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

/**
 * An unsaved assessment of version 1 with the given total and category.
 */
function unsavedReadinessAssessment(int $total, string $categoryCode): ReadinessAssessment
{
    $factory = Factory::factory()->create();
    $questionnaire = ReadinessQuestionnaire::query()->where('is_current', true)->sole();

    $assessment = new ReadinessAssessment;
    $assessment->factory_id = $factory->id;
    $assessment->readiness_questionnaire_id = $questionnaire->id;
    $assessment->readiness_category_id = ReadinessCategory::query()->whereBelongsTo($questionnaire, 'questionnaire')->where('code', $categoryCode)->value('id');
    $assessment->total_score = $total;
    $assessment->submitted_by_user_id = User::factory()->factoryMember($factory)->create()->id;

    return $assessment;
}

it('refuses to store a total no category covers', function (int $total) {
    $assessment = unsavedReadinessAssessment($total, 'b4_automation');

    expect(fn () => $assessment->save())->toThrow(LogicException::class, "covers a total score of {$total}");
    $this->assertDatabaseCount('readiness_assessments', 0);
})->with([9, 41]);

it('refuses to store a category that does not match the total', function () {
    $assessment = unsavedReadinessAssessment(18, 'b4_automation');

    expect(fn () => $assessment->save())->toThrow(LogicException::class, 'A total score of 18 belongs to the basic category.');
    $this->assertDatabaseCount('readiness_assessments', 0);
});

it('stores a total with its matching category', function () {
    $assessment = unsavedReadinessAssessment(18, 'basic');

    $assessment->save();

    $this->assertDatabaseHas('readiness_assessments', ['id' => $assessment->id, 'total_score' => 18]);
});

it('refuses to change or delete a completed assessment or its answers', function (Closure $tamper) {
    $assessment = storedReadinessAssessment(Factory::factory()->create(), 'b');

    expect(fn () => $tamper($assessment))->toThrow(LogicException::class, 'append-only');
    expect(ReadinessAssessment::query()->sole()->total_score)->toBe(20)
        ->and(ReadinessAssessmentAnswer::query()->count())->toBe(10);
})->with([
    'update the total' => [function (ReadinessAssessment $assessment): void {
        $assessment->total_score = 40;
        $assessment->save();
    }],
    'delete the assessment' => [fn (ReadinessAssessment $assessment) => $assessment->delete()],
    'update an answer' => [function (ReadinessAssessment $assessment): void {
        $answer = $assessment->answers()->firstOrFail();
        $answer->points = 4;
        $answer->save();
    }],
    'delete an answer' => [fn (ReadinessAssessment $assessment) => $assessment->answers()->firstOrFail()->delete()],
]);

it('refuses at the database level an answer whose choice belongs to another question', function () {
    $assessment = storedReadinessAssessment(Factory::factory()->create(), 'a');
    $questionnaire = ReadinessQuestionnaire::query()->where('is_current', true)->with('questions.choices')->sole();
    [$first, $second] = $questionnaire->questions->all();

    expect(fn () => DB::table('readiness_assessment_answers')->insert([
        'readiness_assessment_id' => $assessment->id,
        'readiness_question_id' => $first->id,
        'readiness_choice_id' => $second->choices->first()->id,
        'points' => 1,
    ]))->toThrow(QueryException::class);
});
