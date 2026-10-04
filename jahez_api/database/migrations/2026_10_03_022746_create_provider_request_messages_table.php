<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Negotiation messages between a factory and one provider (ADR-015). Append-only;
     * messages are conversation, never formal offers.
     */
    public function up(): void
    {
        Schema::create('provider_request_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->string('author_side', 10);
            $table->text('body');
            $table->timestamp('created_at');

            $table->index(['provider_request_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_request_messages');
    }
};
