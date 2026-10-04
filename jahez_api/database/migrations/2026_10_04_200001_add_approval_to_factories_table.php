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
     * IMC review of a factory's account and profile (owner brief "Phase 3", ADR-021),
     * separate from its readiness classification, which this never changes. New rows
     * start as pending. Factories that existed before the review step have been taking
     * part without one, so they are backfilled as approved and keep working.
     */
    public function up(): void
    {
        Schema::table('factories', function (Blueprint $table) {
            $table->string('approval_status', 20)->default('pending')->after('tax_registration_number');
            $table->text('approval_reason')->nullable()->after('approval_status');
            $table->timestamp('approval_changed_at')->nullable()->after('approval_reason');

            $table->index('approval_status');
        });

        $this->backfillApproval();
    }

    /**
     * Marks every factory that existed before the review step as approved. Public so a
     * test can run it without re-running the migration.
     */
    public function backfillApproval(): void
    {
        DB::table('factories')
            ->where('approval_status', 'pending')
            ->whereNull('approval_changed_at')
            ->update([
                'approval_status' => 'approved',
                'approval_changed_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('factories', function (Blueprint $table) {
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['approval_status', 'approval_reason', 'approval_changed_at']);
        });
    }
};
