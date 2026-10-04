<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The provider fields of the services workbook (all optional: the workbook marks none
     * as required) and the IMC approval state that controls visibility to factories
     * (ADR-014). Existing providers start as pending.
     */
    public function up(): void
    {
        Schema::table('service_providers', function (Blueprint $table) {
            $table->string('representative_name')->nullable()->after('name');
            $table->string('job_title')->nullable()->after('representative_name');
            $table->string('email')->nullable()->after('job_title');
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('website')->nullable()->after('phone');
            $table->unsignedTinyInteger('dx_experience_years')->nullable()->after('website');
            $table->string('approval_status', 20)->default('pending')->after('dx_experience_years');
            $table->text('approval_reason')->nullable()->after('approval_status');
            $table->timestamp('approval_changed_at')->nullable()->after('approval_reason');

            $table->index('approval_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_providers', function (Blueprint $table) {
            $table->dropIndex(['approval_status']);
            $table->dropColumn([
                'representative_name',
                'job_title',
                'email',
                'phone',
                'website',
                'dx_experience_years',
                'approval_status',
                'approval_reason',
                'approval_changed_at',
            ]);
        });
    }
};
