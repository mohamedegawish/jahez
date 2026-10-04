<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Each answer keeps the question text and the choice label and text it was given with
     * (ADR-018 addendum 2), so a later proofreading correction of the seeded text never
     * changes what a stored assessment shows. Existing answers are backfilled with the text
     * their version holds now; the columns stay nullable for rows written before.
     */
    public function up(): void
    {
        Schema::table('readiness_assessment_answers', function (Blueprint $table) {
            $table->text('question_text_ar')->nullable()->after('points');
            $table->string('choice_label_ar', 10)->nullable()->after('question_text_ar');
            $table->text('choice_text_ar')->nullable()->after('choice_label_ar');
        });

        $this->backfillSnapshots();
    }

    /**
     * Copies the current text into answers that have no snapshot yet. Public so that
     * tests can run it without re-running the migration.
     */
    public function backfillSnapshots(): void
    {
        DB::table('readiness_assessment_answers')
            ->whereNull('question_text_ar')
            ->orderBy('id')
            ->select('id', 'readiness_question_id', 'readiness_choice_id')
            ->chunkById(500, function ($answers): void {
                $questions = DB::table('readiness_questions')->whereIn('id', $answers->pluck('readiness_question_id')->unique())->pluck('text_ar', 'id');
                $choices = DB::table('readiness_choices')->whereIn('id', $answers->pluck('readiness_choice_id')->unique())->get(['id', 'label_ar', 'text_ar'])->keyBy('id');

                foreach ($answers as $answer) {
                    $choice = $choices->get($answer->readiness_choice_id);
                    DB::table('readiness_assessment_answers')->where('id', $answer->id)->update([
                        'question_text_ar' => $questions->get($answer->readiness_question_id),
                        'choice_label_ar' => $choice?->label_ar,
                        'choice_text_ar' => $choice?->text_ar,
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('readiness_assessment_answers', function (Blueprint $table) {
            $table->dropColumn(['question_text_ar', 'choice_label_ar', 'choice_text_ar']);
        });
    }
};
