<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Completed digital readiness assessments of a factory (ADR-018). A row is written
     * together with all its answers in one transaction, so every row is complete and its
     * creation time is its completion time (`completed_at`). The
     * server calculates the total and the category and keeps them as history; the
     * composite key makes the category belong to the questionnaire version answered.
     * Rows are append-only.
     */
    public function up(): void
    {
        Schema::create('readiness_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->constrained()->restrictOnDelete();
            $table->foreignId('readiness_questionnaire_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('readiness_category_id');
            $table->unsignedSmallInteger('total_score');
            $table->foreignId('submitted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at');

            $table->foreign(['readiness_category_id', 'readiness_questionnaire_id'], 'readiness_assessments_category_version_foreign')
                ->references(['id', 'readiness_questionnaire_id'])
                ->on('readiness_categories')
                ->restrictOnDelete();
            $table->index(['factory_id', 'completed_at', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('readiness_assessments');
    }
};
