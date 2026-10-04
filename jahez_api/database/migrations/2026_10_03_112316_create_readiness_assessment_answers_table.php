<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The choice selected for each question of an assessment (ADR-018). `points` is the
     * choice's value when the assessment was submitted, so a result never changes later.
     * One answer per question; the composite key makes the choice belong to the question
     * it answers. Rows are append-only.
     */
    public function up(): void
    {
        Schema::create('readiness_assessment_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('readiness_assessment_id')->constrained()->restrictOnDelete();
            $table->foreignId('readiness_question_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('readiness_choice_id');
            $table->unsignedTinyInteger('points');

            $table->unique(['readiness_assessment_id', 'readiness_question_id'], 'readiness_answers_unique_question');
            $table->foreign(['readiness_choice_id', 'readiness_question_id'], 'readiness_answers_choice_question_foreign')
                ->references(['id', 'readiness_question_id'])
                ->on('readiness_choices')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('readiness_assessment_answers');
    }
};
