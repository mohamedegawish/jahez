<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * - `readiness_questionnaires.published_at`: when a version first became current. A
     *   version never published is a draft IMC administrators may still edit (ADR-018
     *   addendum); a published one is frozen. Versions that are current or have been
     *   answered are backfilled as published.
     * - `readiness_assessments.idempotency_key`: an optional client key, so a repeated
     *   submission (a double click, a retried request) returns the assessment already
     *   stored instead of recording a second one.
     */
    public function up(): void
    {
        Schema::table('readiness_questionnaires', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('is_current');
        });

        $this->backfillPublication();

        Schema::table('readiness_assessments', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->after('submitted_by_user_id');

            $table->unique(['factory_id', 'idempotency_key'], 'readiness_assessments_idempotency_unique');
        });
    }

    /**
     * Marks every current or answered questionnaire version as published. Public so that
     * tests/Feature/Database/ReadinessPublicationBackfillTest.php can run it without
     * re-running the migration.
     *
     * created_at is nullable: an answered or current version without one is still
     * published, or the admin screens would show it as an editable draft.
     */
    public function backfillPublication(): void
    {
        DB::table('readiness_questionnaires')
            ->where(fn ($query) => $query
                ->where('is_current', true)
                ->orWhereExists(fn ($assessments) => $assessments
                    ->selectRaw('1')
                    ->from('readiness_assessments')
                    ->whereColumn('readiness_assessments.readiness_questionnaire_id', 'readiness_questionnaires.id')))
            ->update(['published_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('readiness_assessments', function (Blueprint $table) {
            $table->dropUnique('readiness_assessments_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });

        Schema::table('readiness_questionnaires', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }
};
