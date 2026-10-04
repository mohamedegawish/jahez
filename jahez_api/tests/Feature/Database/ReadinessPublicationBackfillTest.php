<?php

use App\Models\Factory;
use App\Models\ReadinessQuestionnaire;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The backfill of 2026_10_03_200005: versions that are current or have been answered count as
 * published; a version nobody has used stays a draft.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

it('publishes current and answered versions, even when created_at is missing, and leaves unused versions as drafts', function () {
    $v1 = ReadinessQuestionnaire::query()->where('version', 1)->sole();

    // v2: answered, then retired, and without a creation time (the column is nullable).
    $v2 = $v1->copyAsDraft();
    ReadinessQuestionnaire::query()->whereKey($v1->id)->update(['is_current' => null]);
    ReadinessQuestionnaire::query()->whereKey($v2->id)->update(['is_current' => true]);
    storedReadinessAssessment(Factory::factory()->create(), 'b');
    ReadinessQuestionnaire::query()->whereKey($v2->id)->update(['is_current' => null]);
    ReadinessQuestionnaire::query()->whereKey($v1->id)->update(['is_current' => true]);

    // v3: never current, never answered.
    $v3 = $v1->copyAsDraft();

    DB::table('readiness_questionnaires')->update(['published_at' => null]);
    DB::table('readiness_questionnaires')->where('id', $v2->id)->update(['created_at' => null]);

    (require database_path('migrations/2026_10_03_200005_add_publication_and_idempotency_to_readiness_tables.php'))->backfillPublication();

    $published = ReadinessQuestionnaire::query()->orderBy('version')->get()->mapWithKeys(fn (ReadinessQuestionnaire $q): array => [$q->version => $q->published_at !== null])->all();
    expect($published)->toBe([1 => true, 2 => true, 3 => false])
        ->and(ReadinessQuestionnaire::query()->findOrFail($v2->id)->isDraft())->toBeFalse()
        ->and(ReadinessQuestionnaire::query()->findOrFail($v3->id)->isDraft())->toBeTrue();
});
