<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only record of security-relevant actions (ADR-012). Rows are never updated
     * or deleted by the application; retention is an open question (OQ-25).
     *
     * actor_user_id deliberately has no foreign key: the key check would take a shared
     * lock on the actor's users row for every entry, which can deadlock with the
     * administrator row locks in UserController.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 100);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('subject_type', 50)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('request_id', 128)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['actor_user_id', 'id']);
            $table->index(['subject_type', 'subject_id', 'id']);
            $table->index(['event', 'id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
