<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Where a plan item sits in one version (ADR-025): its stage, its order inside the
     * stage, the provider IMC assigned (optional; binding for the factory's request, owner
     * decision), instructions for the factory, IMC's internal notes and planned dates.
     *
     * - The composite foreign key keeps the stage in the same version.
     * - Each item appears at most once per version.
     * - `(id, version)` is unique so a dependency can require both ends in one version.
     */
    public function up(): void
    {
        Schema::create('transformation_plan_stage_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transformation_plan_version_id');
            $table->unsignedBigInteger('transformation_plan_stage_id');
            $table->unsignedBigInteger('transformation_plan_item_id');
            $table->unsignedSmallInteger('position');
            $table->foreignId('service_provider_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('instructions_ar')->nullable();
            $table->text('internal_notes')->nullable();
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->timestamps();

            $table->foreign('transformation_plan_version_id', 'plan_stage_items_version_foreign')->references('id')->on('transformation_plan_versions')->restrictOnDelete();
            $table->foreign('transformation_plan_item_id', 'plan_stage_items_item_foreign')->references('id')->on('transformation_plan_items')->restrictOnDelete();
            $table->foreign(['transformation_plan_stage_id', 'transformation_plan_version_id'], 'plan_stage_items_stage_version_foreign')
                ->references(['id', 'transformation_plan_version_id'])
                ->on('transformation_plan_stages')
                ->restrictOnDelete();
            $table->unique(['transformation_plan_stage_id', 'position'], 'plan_stage_items_stage_position_unique');
            $table->unique(['transformation_plan_version_id', 'transformation_plan_item_id'], 'plan_stage_items_version_item_unique');
            $table->unique(['id', 'transformation_plan_version_id'], 'plan_stage_items_id_version_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transformation_plan_stage_items');
    }
};
