<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A factory's request for one catalog service (ADR-015). The content is shared with
     * every provider it is sent to; each provider's answer lives in provider_requests.
     */
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->constrained()->restrictOnDelete();
            $table->foreignId('catalog_service_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->text('need');
            $table->text('requirements')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['factory_id', 'id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
