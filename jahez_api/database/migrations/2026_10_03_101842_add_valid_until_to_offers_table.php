<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The last day an offer version may be accepted, as the provider states it in its own
     * offer. Optional: without it the offer does not expire. The platform sets no
     * validity period of its own (OQ-38).
     */
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->date('valid_until')->nullable()->after('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('valid_until');
        });
    }
};
