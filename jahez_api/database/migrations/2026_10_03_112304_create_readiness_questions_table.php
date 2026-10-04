<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The questions of a questionnaire version, each under one pillar (ADR-018).
     * `number` is the source numbering (س1 … س10) and the display order.
     */
    public function up(): void
    {
        Schema::create('readiness_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('readiness_questionnaire_id')->constrained()->restrictOnDelete();
            $table->foreignId('readiness_pillar_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->unsignedSmallInteger('number');
            $table->text('text_ar');
            $table->string('source_ref');
            $table->timestamps();

            $table->unique(['readiness_questionnaire_id', 'code']);
            $table->unique(['readiness_questionnaire_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('readiness_questions');
    }
};
