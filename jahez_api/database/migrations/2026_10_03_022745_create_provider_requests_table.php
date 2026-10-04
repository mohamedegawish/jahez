<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A service request as sent to one provider (ADR-015): that provider's status, and the
     * private thread (messages, offers) between it and the factory. One row per provider
     * and request.
     */
    public function up(): void
    {
        Schema::create('provider_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_provider_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->text('status_reason')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->unique(['service_request_id', 'service_provider_id']);
            $table->index(['service_provider_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_requests');
    }
};
