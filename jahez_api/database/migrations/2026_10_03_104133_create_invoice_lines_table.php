<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Invoice lines: a whole-number quantity times a unit amount, computed exactly in
     * minor units. Whole quantities avoid a rounding rule nobody has decided.
     */
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('description', 500);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_amount', 14, 2);
            $table->decimal('line_amount', 14, 2);
            $table->timestamps();

            $table->unique(['invoice_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
