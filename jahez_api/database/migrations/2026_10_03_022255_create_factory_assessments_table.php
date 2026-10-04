<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Manual IMC classifications of a factory (ADR-016, OQ-07 interim approved by the owner
     * on 2026-10-03): a maturity tier chosen by an IMC administrator with a justification.
     * There is deliberately no score column: no scoring formula or thresholds are approved
     * (OQ-06, OQ-07). Rows are append-only; each new classification is a new row.
     */
    public function up(): void
    {
        Schema::create('factory_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->constrained()->restrictOnDelete();
            $table->foreignId('maturity_tier_id')->constrained()->restrictOnDelete();
            $table->text('justification');
            $table->date('assessed_on');
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->index(['factory_id', 'assessed_on', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('factory_assessments');
    }
};
