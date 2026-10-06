<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The ordered stages (مراحل) of one plan version (ADR-025). `position` is the order
     * IMC chose. `factory_instructions_ar` is shown to the factory, `internal_notes` only
     * to IMC. Planned dates are optional and informational. A draft's stages are replaced
     * on every save; the application deletes child rows first (no cascades, so CHECK
     * constraints stay allowed on MySQL 8). A published version is never changed.
     */
    public function up(): void
    {
        Schema::create('transformation_plan_stages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transformation_plan_version_id');
            $table->unsignedSmallInteger('position');
            $table->string('name_ar', 200);
            $table->text('objective_ar')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('factory_instructions_ar')->nullable();
            $table->text('internal_notes')->nullable();
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->timestamps();

            $table->foreign('transformation_plan_version_id', 'plan_stages_version_foreign')->references('id')->on('transformation_plan_versions')->restrictOnDelete();
            $table->unique(['transformation_plan_version_id', 'position'], 'plan_stages_version_position_unique');
            $table->unique(['id', 'transformation_plan_version_id'], 'plan_stages_id_version_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transformation_plan_stages');
    }
};
