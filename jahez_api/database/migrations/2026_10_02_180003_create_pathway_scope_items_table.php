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
        Schema::create('pathway_scope_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pathway_level_id')->constrained()->restrictOnDelete();
            $table->string('group_ar')->nullable();
            $table->string('group_en')->nullable();
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
        Schema::dropIfExists('pathway_scope_items');
    }
};
