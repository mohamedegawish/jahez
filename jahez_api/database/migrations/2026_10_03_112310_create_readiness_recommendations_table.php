<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The service lines the source roadmap recommends for a readiness category, in
     * source order and with the source text (ADR-018).
     */
    public function up(): void
    {
        Schema::create('readiness_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('readiness_category_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->text('text_ar');
            $table->string('source_ref');
            $table->timestamps();

            $table->unique(['readiness_category_id', 'sort_order'], 'readiness_recommendations_unique_position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('readiness_recommendations');
    }
};
