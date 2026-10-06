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
     * One catalog service in a transformation plan, with its execution state (ADR-025).
     * The row belongs to the plan, not to a version: versions place it in a stage
     * (transformation_plan_stage_items), so publishing a revision never resets its status,
     * dates or linked service requests. The unique key keeps a service once per plan; a
     * retried service reuses its row.
     *
     * `execution_status` (not_started, in_progress, on_hold, completed, cancelled) is
     * recorded by IMC (owner decision; OQ-52) and is separate from the status of any
     * service request linked to the item.
     */
    public function up(): void
    {
        Schema::create('transformation_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transformation_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('catalog_service_id')->constrained()->restrictOnDelete();
            $table->string('execution_status', 20)->default('not_started');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status_reason', 1000)->nullable();
            $table->foreignId('status_changed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->unique(['transformation_plan_id', 'catalog_service_id'], 'plan_items_plan_service_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE transformation_plan_items ADD CONSTRAINT transformation_plan_items_status_check
            CHECK (execution_status IN ('not_started', 'in_progress', 'on_hold', 'completed', 'cancelled'))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transformation_plan_items');
    }
};
