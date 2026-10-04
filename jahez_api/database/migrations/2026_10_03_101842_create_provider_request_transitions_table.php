<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The status history of each provider request, shown to its two parties: one row per
     * change, including the creation (from_status null). Append-only. The actor is
     * indexed, not a foreign key, for the same reason as the audit log (ADR-012): a
     * write inside a marketplace transaction must not lock users rows. History starts
     * with this migration; earlier changes are in the audit log.
     */
    public function up(): void
    {
        Schema::create('provider_request_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_request_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->timestamp('created_at');

            $table->index(['provider_request_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_request_transitions');
    }
};
