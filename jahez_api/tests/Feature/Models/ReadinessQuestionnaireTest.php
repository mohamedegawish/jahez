<?php

use App\Models\ReadinessQuestionnaire;
use Database\Seeders\ReferenceDataSeeder;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

it('classifies a total with the source thresholds of version 1', function (int $total, string $category) {
    $questionnaire = ReadinessQuestionnaire::query()->where('is_current', true)->sole();

    expect($questionnaire->categoryForScore($total)->code->value)->toBe($category);
})->with([
    'lowest total, 10' => [10, 'b4_automation'],
    'top of B4 Automation, 17' => [17, 'b4_automation'],
    'bottom of Basic, 18' => [18, 'basic'],
    'top of Basic, 25' => [25, 'basic'],
    'bottom of Advanced, 26' => [26, 'advanced'],
    'top of Advanced, 33' => [33, 'advanced'],
    'bottom of Smart, 34' => [34, 'smart'],
    'highest total, 40' => [40, 'smart'],
]);

it('gives every total from 10 to 40 exactly one category', function () {
    $questionnaire = ReadinessQuestionnaire::query()->where('is_current', true)->sole();

    $categories = array_map(fn (int $total): string => $questionnaire->categoryForScore($total)->code->value, range(10, 40));

    expect(array_count_values($categories))->toBe(['b4_automation' => 8, 'basic' => 8, 'advanced' => 8, 'smart' => 7]);
});

it('refuses to classify a total outside 10 to 40', function (int $total) {
    $questionnaire = ReadinessQuestionnaire::query()->where('is_current', true)->sole();

    expect(fn () => $questionnaire->categoryForScore($total))
        ->toThrow(InvalidArgumentException::class, "No single readiness category of questionnaire version 1 covers a total score of {$total}.");
})->with([0, 9, 41]);

it('reports the lowest and highest total the choices allow', function () {
    expect(ReadinessQuestionnaire::query()->where('is_current', true)->sole()->scoreRange())->toBe(['min' => 10, 'max' => 40]);
});
