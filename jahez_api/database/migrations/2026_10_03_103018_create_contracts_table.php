<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Contract drafts for an agreement (ADR-017). Under the OQ-17 interim every contract
     * is a draft and not legally binding: there is no signature, approval or execution
     * state until the owner decides the parties, templates and e-signature. Each draft
     * carries the knowledge-transfer commitment DOC §6 requires: at least two IMC
     * engineers trained, and a detailed training plan (the legal memo attachment needs
     * documents, which do not exist yet). A cancelled draft stays; a new draft is the
     * next version.
     */
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedTinyInteger('knowledge_transfer_trainees');
            $table->text('knowledge_transfer_plan');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('drafted_by_user_id')->index();
            $table->text('status_reason')->nullable();
            $table->unsignedBigInteger('status_changed_by_user_id')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->unique(['agreement_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
