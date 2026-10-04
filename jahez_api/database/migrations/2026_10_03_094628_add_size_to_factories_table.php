<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The company size a factory declares (DOC §1: small, medium, large industrial
     * companies). Nullable and validated against a configurable list, never derived
     * (OQ-04 interim).
     */
    public function up(): void
    {
        Schema::table('factories', function (Blueprint $table) {
            $table->string('size', 20)->nullable()->after('name')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('factories', function (Blueprint $table) {
            $table->dropIndex(['size']);
            $table->dropColumn('size');
        });
    }
};
