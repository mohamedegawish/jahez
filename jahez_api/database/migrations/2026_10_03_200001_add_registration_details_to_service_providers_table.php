<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The registration details the owner asked for on 2026-10-03 (ADR-019): the legal
     * name, a description, the business address and the commercial and tax registration
     * numbers. All nullable, so existing providers keep working; none is mandatory until
     * OQ-36 / OQ-18 decide otherwise. The legal name and registration numbers are verified
     * by IMC: once a provider is approved, its members change them only through a
     * reviewed change request.
     */
    public function up(): void
    {
        Schema::table('service_providers', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('name');
            $table->text('description')->nullable()->after('legal_name');
            $table->string('governorate', 100)->nullable()->after('dx_experience_years');
            $table->string('city', 100)->nullable()->after('governorate');
            $table->string('address', 500)->nullable()->after('city');
            $table->string('commercial_registration_number', 50)->nullable()->after('address');
            $table->string('tax_registration_number', 50)->nullable()->after('commercial_registration_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_providers', function (Blueprint $table) {
            $table->dropColumn([
                'legal_name',
                'description',
                'governorate',
                'city',
                'address',
                'commercial_registration_number',
                'tax_registration_number',
            ]);
        });
    }
};
