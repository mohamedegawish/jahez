<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Promotions (إعلان) IMC administrators place on a provider's listing of a catalog
     * service (ADR-020). A promotion only orders and labels a listing a factory may
     * already see: it never makes a provider or service eligible. It is shown from
     * starts_at until ends_at (open-ended when null) or until an administrator ends it.
     * Higher priority is shown first. Promotions are never deleted.
     */
    public function up(): void
    {
        Schema::create('service_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('catalog_service_id')->constrained()->restrictOnDelete();
            $table->string('headline', 120)->nullable();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['catalog_service_id', 'service_provider_id']);
            $table->index(['ended_at', 'starts_at', 'ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_promotions');
    }
};
