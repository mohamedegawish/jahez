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
     * IMC review of each service a provider lists (owner brief "Phase 3", ADR-021): a
     * listing reaches factories only once IMC approves it, on top of the provider's own
     * approval. New listings start as pending. Listings that existed before the review
     * step were already visible, so they are backfilled as approved.
     */
    public function up(): void
    {
        Schema::table('catalog_service_service_provider', function (Blueprint $table) {
            $table->string('status', 20)->default('pending');
            $table->text('status_reason')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('submitted_at')->nullable()->useCurrent();

            $table->index('status');
        });

        $this->backfillReview();
    }

    /**
     * Marks every listing that existed before the review step as approved. Public so a
     * test can run it without re-running the migration.
     */
    public function backfillReview(): void
    {
        DB::table('catalog_service_service_provider')
            ->where('status', 'pending')
            ->whereNull('status_changed_at')
            ->update(['status' => 'approved', 'status_changed_at' => DB::raw('CURRENT_TIMESTAMP')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalog_service_service_provider', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'status_reason', 'status_changed_at', 'submitted_at']);
        });
    }
};
