<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The registration details the owner asked for on 2026-10-03 (ADR-019, OQ-19): the
     * legal name, the contact person, the address and the commercial and tax registration
     * numbers. All nullable, so existing factories keep working, and none is mandatory:
     * the sources state no required factory document.
     */
    public function up(): void
    {
        Schema::table('factories', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('name');
            $table->string('contact_name')->nullable()->after('size');
            $table->string('contact_job_title')->nullable()->after('contact_name');
            $table->string('contact_email')->nullable()->after('contact_job_title');
            $table->string('contact_phone', 30)->nullable()->after('contact_email');
            $table->string('website')->nullable()->after('contact_phone');
            $table->string('governorate', 100)->nullable()->after('website');
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
        Schema::table('factories', function (Blueprint $table) {
            $table->dropColumn([
                'legal_name',
                'contact_name',
                'contact_job_title',
                'contact_email',
                'contact_phone',
                'website',
                'governorate',
                'city',
                'address',
                'commercial_registration_number',
                'tax_registration_number',
            ]);
        });
    }
};
