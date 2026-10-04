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
        Schema::create('factory_sector', function (Blueprint $table) {
            $table->foreignId('factory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sector_id')->constrained()->restrictOnDelete();

            $table->primary(['factory_id', 'sector_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('factory_sector');
    }
};
