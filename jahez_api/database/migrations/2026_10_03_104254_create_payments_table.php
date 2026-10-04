<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Payments of an issued invoice through the configured gateway (ADR-017). A payment
     * starts `pending`; only verified gateway evidence (a signed callback or a status
     * query) moves it on. The client's Idempotency-Key is unique per invoice, so a
     * retried start never creates a second charge; the gateway reference is unique per
     * gateway, so a callback finds exactly one payment.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('gateway', 50);
            $table->string('gateway_reference', 191)->nullable();
            $table->string('idempotency_key', 100);
            $table->string('status', 20)->default('pending')->index();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->text('checkout_url')->nullable();
            $table->string('failure_reason', 191)->nullable();
            $table->unsignedBigInteger('initiated_by_user_id')->index();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->unique(['invoice_id', 'idempotency_key']);
            $table->unique(['gateway', 'gateway_reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
