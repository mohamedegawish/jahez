<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Criteria are versioned so a later change of weights never rewrites the basis
     * of evaluations already scored under an earlier version.
     */
    public function up(): void
    {
        Schema::create('evaluation_criteria', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('version');
            $table->string('code', 50);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('sub_elements_ar');
            $table->decimal('weight_percent', 5, 2);
            $table->text('verification_ar');
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();

            $table->unique(['version', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_criteria');
    }
};
