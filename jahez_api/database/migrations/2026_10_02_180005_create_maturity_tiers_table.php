<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * score_min / score_max stay NULL until the owner approves numeric readiness
     * thresholds (docs/open-questions.md OQ-07); the source gives qualitative bands only.
     */
    public function up(): void
    {
        Schema::create('maturity_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pathway_level_id')->constrained()->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('readiness_band_ar');
            $table->decimal('score_min', 5, 2)->nullable();
            $table->decimal('score_max', 5, 2)->nullable();
            $table->text('operational_state_ar');
            $table->text('approved_path_ar');
            $table->text('expected_impact_ar');
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maturity_tiers');
    }
};
