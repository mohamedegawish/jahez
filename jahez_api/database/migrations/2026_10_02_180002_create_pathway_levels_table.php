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
        Schema::create('pathway_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pathway_id')->constrained()->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name_ar');
            $table->string('subtitle_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->text('target_group_ar');
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pathway_levels');
    }
};
