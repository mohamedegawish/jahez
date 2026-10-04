<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Versions of the digital readiness questionnaire (ADR-018). `is_current` holds true
     * or NULL and is unique, so at most one version is current. A version that factories
     * have answered is never changed in place: its scores and thresholds are history.
     */
    public function up(): void
    {
        Schema::create('readiness_questionnaires', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('version')->unique();
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->string('source_ref');
            $table->boolean('is_current')->nullable()->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('readiness_questionnaires');
    }
};
