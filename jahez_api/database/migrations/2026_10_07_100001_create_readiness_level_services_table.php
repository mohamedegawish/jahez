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
     * The catalog services IMC makes available to factories of each digital readiness
     * level (ADR-025). `level` is the stable readiness category code, so a row applies
     * whichever questionnaire version classified the factory. A service may be available
     * to several levels. `is_active` switches a service off for a level without losing
     * the row; removing a row never touches the catalog service. Nothing is seeded: IMC
     * decides every row (owner decision, 2026-10-05).
     */
    public function up(): void
    {
        Schema::create('readiness_level_services', function (Blueprint $table) {
            $table->id();
            $table->string('level', 30);
            $table->foreignId('catalog_service_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['level', 'catalog_service_id']);
            $table->index(['catalog_service_id', 'is_active']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE readiness_level_services ADD CONSTRAINT readiness_level_services_level_check
            CHECK (level IN ('b4_automation', 'basic', 'advanced', 'smart'))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('readiness_level_services');
    }
};
