<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every verified gateway event, whether it changed a payment or not, for
     * reconciliation (ADR-017). The gateway's event id is unique per gateway, so a
     * repeated callback is recorded once and applied at most once. `outcome` says what
     * happened: applied, duplicate, unknown_payment, amount_mismatch or
     * ignored_transition. Only the normalised fields are kept, never the raw payload.
     */
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 50);
            $table->string('event_id', 191);
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('gateway_reference', 191);
            $table->string('reported_status', 20);
            $table->decimal('reported_amount', 14, 2);
            $table->char('reported_currency', 3);
            $table->string('source', 20);
            $table->string('outcome', 30);
            $table->timestamp('created_at');

            $table->unique(['gateway', 'event_id']);
            $table->index(['payment_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
