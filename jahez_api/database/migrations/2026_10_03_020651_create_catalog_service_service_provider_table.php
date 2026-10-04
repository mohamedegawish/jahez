<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The catalog services a provider offers (the workbook's service checkboxes).
     */
    public function up(): void
    {
        Schema::create('catalog_service_service_provider', function (Blueprint $table) {
            $table->foreignId('catalog_service_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_provider_id')->constrained()->cascadeOnDelete();

            $table->primary(['catalog_service_id', 'service_provider_id']);
            $table->index('service_provider_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_service_service_provider');
    }
};
