<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * IMC evaluations of a provider against the DOC §6 criteria (OQ-13 interim: the
     * weights are stored as the source states them, with their version). The scale and
     * pass mark in force when the evaluation was recorded are kept with it; both are
     * null while the owner has not approved them, and then no score or total exists.
     * Rows are append-only: a re-evaluation is a new row.
     */
    public function up(): void
    {
        Schema::create('provider_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_provider_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('criteria_version');
            $table->text('summary');
            $table->date('evaluated_on');
            $table->unsignedSmallInteger('scale_max')->nullable();
            $table->decimal('weighted_total', 5, 2)->nullable();
            $table->decimal('pass_mark', 5, 2)->nullable();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->index(['service_provider_id', 'evaluated_on', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_evaluations');
    }
};
