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
     * A factory's digital transformation plan (خطة التحول الرقمي), written by IMC
     * (ADR-025). Its content lives in versions: at most one draft, at most one published
     * version the factory sees, and superseded versions kept as history.
     *
     * - `status`: draft (never published), published, suspended, closed.
     * - `is_open` is true for a plan that is not closed and NULL otherwise; with the
     *   unique key a factory has at most one open plan.
     * - `based_on_readiness_assessment_id`: the factory's current assessment when the plan
     *   was created, then when IMC last published it. A later assessment never rewrites
     *   the plan; IMC is warned and reviews it.
     */
    public function up(): void
    {
        Schema::create('transformation_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->boolean('is_open')->nullable()->default(true);
            $table->string('status_reason', 1000)->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('based_on_readiness_assessment_id')->nullable()->constrained('readiness_assessments')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['factory_id', 'is_open']);
            $table->index(['status', 'id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE transformation_plans ADD CONSTRAINT transformation_plans_status_check CHECK (
                status IN ('draft', 'published', 'suspended', 'closed')
                AND ((status = 'closed' AND is_open IS NULL) OR (status <> 'closed' AND is_open = 1))
            )
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transformation_plans');
    }
};
