<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * How far each participant has read a negotiation (ADR-020): the last message id
     * they saw. Unread messages are the other side's messages after it. One row per
     * user and thread; it only moves forward.
     */
    public function up(): void
    {
        Schema::create('provider_request_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id');
            $table->timestamp('read_at');

            $table->unique(['provider_request_id', 'user_id']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_request_reads');
    }
};
