<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The last invoice number used per prefix. Issuing locks the prefix row, so numbers
     * are gap-free and never repeat, even when invoices are issued at the same time.
     */
    public function up(): void
    {
        Schema::create('invoice_number_sequences', function (Blueprint $table) {
            $table->string('prefix', 20)->primary();
            $table->unsignedBigInteger('last_number');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_number_sequences');
    }
};
