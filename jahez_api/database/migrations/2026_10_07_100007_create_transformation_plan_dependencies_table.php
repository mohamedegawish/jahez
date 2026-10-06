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
     * Finish-to-start dependencies between the placed items of one plan version (ADR-025):
     * an item may start only once every item it depends on is completed. Items with no
     * path between them may run in parallel. The composite foreign keys keep both ends in
     * the same version and the CHECK refuses a self-dependency. Cycles are refused by the
     * application before saving (TransformationPlanDraft).
     */
    public function up(): void
    {
        Schema::create('transformation_plan_dependencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transformation_plan_version_id');
            $table->unsignedBigInteger('stage_item_id');
            $table->unsignedBigInteger('depends_on_stage_item_id');
            $table->timestamps();

            $table->foreign('transformation_plan_version_id', 'plan_dependencies_version_foreign')->references('id')->on('transformation_plan_versions')->restrictOnDelete();
            $table->foreign(['stage_item_id', 'transformation_plan_version_id'], 'plan_dependencies_item_version_foreign')
                ->references(['id', 'transformation_plan_version_id'])
                ->on('transformation_plan_stage_items')
                ->restrictOnDelete();
            $table->foreign(['depends_on_stage_item_id', 'transformation_plan_version_id'], 'plan_dependencies_prerequisite_version_foreign')
                ->references(['id', 'transformation_plan_version_id'])
                ->on('transformation_plan_stage_items')
                ->restrictOnDelete();
            $table->unique(['stage_item_id', 'depends_on_stage_item_id'], 'plan_dependencies_pair_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE transformation_plan_dependencies ADD CONSTRAINT transformation_plan_dependencies_not_self_check
            CHECK (stage_item_id <> depends_on_stage_item_id)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transformation_plan_dependencies');
    }
};
