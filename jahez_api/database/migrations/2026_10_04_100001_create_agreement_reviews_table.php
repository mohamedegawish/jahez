<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * IMC's decision on an agreement (ADR-020): the ministry approval step the owner's
     * Phase 2 brief places between an agreed offer and any contract draft or invoice.
     * Agreements are immutable, so the decision is its own append-only record, at most
     * one per agreement (the unique key makes a repeated or concurrent decision fail).
     * No row means the agreement is awaiting review. The reviewer is indexed, not a
     * foreign key, like the other actors written inside marketplace transactions.
     */
    public function up(): void
    {
        Schema::create('agreement_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->unique()->constrained()->restrictOnDelete();
            $table->string('decision', 20);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable()->index();
            $table->timestamp('decided_at');

            $table->index(['decision', 'agreement_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agreement_reviews');
    }
};
