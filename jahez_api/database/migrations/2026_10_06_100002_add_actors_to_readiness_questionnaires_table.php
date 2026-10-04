<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Who drafted, last changed and published each questionnaire version (ADR-018
     * addendum 2). The seeded version 1 has none: it is the source document. The audit
     * log keeps the full history; these columns let the version list show it directly.
     */
    public function up(): void
    {
        Schema::table('readiness_questionnaires', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->after('published_at')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by_user_id')->nullable()->after('updated_by_user_id')->constrained('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('readiness_questionnaires', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_by_user_id');
            $table->dropConstrainedForeignId('updated_by_user_id');
            $table->dropConstrainedForeignId('created_by_user_id');
        });
    }
};
