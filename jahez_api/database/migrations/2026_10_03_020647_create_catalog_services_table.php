<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The services listed under each workbook category (ADR-014), with the Arabic text as
     * the workbook prints it. The same text may appear in two categories.
     */
    public function up(): void
    {
        Schema::create('catalog_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_category_id')->constrained()->restrictOnDelete();
            $table->string('code', 60)->unique();
            $table->string('name_ar');
            $table->string('source_ref', 30);
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();

            $table->unique(['service_category_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_services');
    }
};
