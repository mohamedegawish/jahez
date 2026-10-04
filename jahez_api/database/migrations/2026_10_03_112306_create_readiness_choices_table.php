<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The answer choices of a question (أ ب ج د) and the points each is worth (ADR-018).
     * The unique (id, readiness_question_id) index lets an answer reference a choice
     * together with its question, so a choice of another question cannot be stored.
     */
    public function up(): void
    {
        Schema::create('readiness_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('readiness_question_id')->constrained()->restrictOnDelete();
            $table->string('code', 10);
            $table->string('label_ar', 10);
            $table->text('text_ar');
            $table->unsignedTinyInteger('points');
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();

            $table->unique(['readiness_question_id', 'code']);
            $table->unique(['id', 'readiness_question_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('readiness_choices');
    }
};
