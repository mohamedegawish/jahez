<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The pillars (المحاور) of a questionnaire version (ADR-018).
     */
    public function up(): void
    {
        Schema::create('readiness_pillars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('readiness_questionnaire_id')->constrained()->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();

            $table->unique(['readiness_questionnaire_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('readiness_pillars');
    }
};
