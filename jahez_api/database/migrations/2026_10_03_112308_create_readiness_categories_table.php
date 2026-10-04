<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The readiness categories of a questionnaire version, each with its total-score
     * range and the source roadmap text (ADR-018). The ranges belong to the version, so a
     * later change of thresholds is a new version, and old results keep theirs.
     */
    public function up(): void
    {
        Schema::create('readiness_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('readiness_questionnaire_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name_en');
            $table->string('name_ar');
            $table->text('description_ar');
            $table->unsignedSmallInteger('min_score');
            $table->unsignedSmallInteger('max_score');
            $table->text('focus_ar');
            $table->text('steps_ar');
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();

            $table->unique(['readiness_questionnaire_id', 'code']);
            $table->unique(['id', 'readiness_questionnaire_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('readiness_categories');
    }
};
