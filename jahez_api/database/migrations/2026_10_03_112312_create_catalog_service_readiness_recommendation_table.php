<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The existing catalog services a recommendation line corresponds to (ADR-018). A
     * line the catalog does not offer has no rows; no catalog service is added for it.
     */
    public function up(): void
    {
        Schema::create('catalog_service_readiness_recommendation', function (Blueprint $table) {
            $table->foreignId('catalog_service_id')->constrained(indexName: 'readiness_recommendation_service_foreign')->restrictOnDelete();
            $table->foreignId('readiness_recommendation_id')->constrained(indexName: 'readiness_recommendation_line_foreign')->cascadeOnDelete();

            $table->primary(['catalog_service_id', 'readiness_recommendation_id']);
            $table->index('readiness_recommendation_id', 'readiness_recommendation_line_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_service_readiness_recommendation');
    }
};
