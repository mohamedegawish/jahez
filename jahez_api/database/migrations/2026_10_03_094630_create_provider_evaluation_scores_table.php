<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One written assessment per DOC §6 criterion of an evaluation, with a score only
     * when a scale was approved at the time (OQ-13).
     */
    public function up(): void
    {
        Schema::create('provider_evaluation_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_evaluation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluation_criterion_id')->constrained('evaluation_criteria')->restrictOnDelete();
            $table->decimal('score', 6, 2)->nullable();
            $table->text('note');

            $table->unique(['provider_evaluation_id', 'evaluation_criterion_id'], 'provider_evaluation_scores_unique_criterion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_evaluation_scores');
    }
};
