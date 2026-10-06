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
     * The readiness levels a factory opened by completing its transformation plan's
     * services of the level below (ADR-026, owner decision 2026-10-06). A factory's level
     * is the highest of its current assessment's category and these rows. Append-only: an
     * opened level is never closed again. `from_level` is the level whose plan services
     * were completed; the plan and the IMC administrator who recorded the last completion
     * (or published the version) are kept for the history.
     */
    public function up(): void
    {
        Schema::create('readiness_level_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->constrained()->restrictOnDelete();
            $table->string('level', 30);
            $table->string('from_level', 30);
            $table->foreignId('transformation_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('unlocked_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('unlocked_at');
            $table->timestamps();

            $table->unique(['factory_id', 'level']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE readiness_level_unlocks ADD CONSTRAINT readiness_level_unlocks_level_check
            CHECK (level IN ('basic', 'advanced', 'smart'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE readiness_level_unlocks ADD CONSTRAINT readiness_level_unlocks_from_level_check
            CHECK (from_level IN ('b4_automation', 'basic', 'advanced'))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('readiness_level_unlocks');
    }
};
