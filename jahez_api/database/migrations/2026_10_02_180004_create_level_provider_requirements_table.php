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
        Schema::create('level_provider_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pathway_level_id')->constrained()->restrictOnDelete();
            $table->text('text_ar');
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();

            $table->unique(['pathway_level_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('level_provider_requirements');
    }
};
