<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A provider member's request to change legal information IMC has verified (ADR-019):
     * the legal name, the registration numbers and the registration documents. Nothing
     * changes on the provider until an IMC administrator approves the request.
     * `is_open` is true while the request is pending and NULL afterwards, so the unique
     * key allows one pending request per provider and any number of closed ones.
     */
    public function up(): void
    {
        Schema::create('provider_profile_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->index();
            $table->boolean('is_open')->nullable();
            $table->json('changes')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();

            $table->unique(['service_provider_id', 'is_open'], 'provider_change_requests_one_open');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_profile_change_requests');
    }
};
