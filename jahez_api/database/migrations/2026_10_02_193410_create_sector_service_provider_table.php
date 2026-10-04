<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sector_service_provider', function (Blueprint $table) {
            $table->foreignId('service_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sector_id')->constrained()->restrictOnDelete();

            $table->primary(['service_provider_id', 'sector_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sector_service_provider');
    }
};
