<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The versions of a financial policy (ADR-023). A version is edited only as a draft,
     * submitted, then approved or rejected by a different administrator. An approved
     * version is never edited: its parameters and effective period are the record that
     * agreements, contracts and invoices reference. The only later change to an approved
     * version is a shorter end date, set when a successor supersedes it or when it is
     * ended early, both audited. Versions are never deleted.
     */
    public function up(): void
    {
        Schema::create('financial_policy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_policy_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('status', 20)->default('draft');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('parameters');
            $table->text('change_reason');
            $table->unsignedBigInteger('created_by_user_id')->index();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedBigInteger('submitted_by_user_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('decided_by_user_id')->nullable()->index();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('superseded_by_version_id')->nullable()->constrained('financial_policy_versions')->restrictOnDelete();
            $table->unsignedBigInteger('status_changed_by_user_id')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestamps();

            $table->unique(['financial_policy_id', 'version']);
            $table->index(['financial_policy_id', 'status', 'effective_from'], 'financial_policy_versions_resolution_index');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_policy_versions');
    }
};
