<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Invoices for an agreement (ADR-017). Money is DECIMAL(14,2) in the agreement's
     * currency. A draft has lines and a subtotal but no number, tax or total: those are
     * fixed when it is issued, under the owner-configured numbering and tax rule (OQ-16).
     * The revenue share is informational and present only when that rule is configured
     * (OQ-15). Users are indexed, not foreign keys, as in the audit log (ADR-012).
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->restrictOnDelete();
            $table->string('number', 50)->nullable()->unique();
            $table->string('status', 20)->default('draft')->index();
            $table->string('issuer', 20);
            $table->char('currency', 3);
            $table->decimal('subtotal_amount', 14, 2)->default(0);
            $table->decimal('tax_rate_percent', 5, 2)->nullable();
            $table->decimal('tax_amount', 14, 2)->nullable();
            $table->decimal('total_amount', 14, 2)->nullable();
            $table->decimal('revenue_share_percent', 5, 2)->nullable();
            $table->decimal('revenue_share_amount', 14, 2)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->index();
            $table->unsignedBigInteger('issued_by_user_id')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->index(['agreement_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
