<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Files an organization uploaded (ADR-019): its logo and its commercial and tax
     * registration documents. The files live on a private disk and are served only by
     * authorized API endpoints, never by a public URL. Exactly one of factory_id and
     * service_provider_id is set. Replaced files are kept as `superseded`, so earlier
     * legal documents stay available for review.
     */
    public function up(): void
    {
        Schema::create('organization_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('service_provider_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 40);
            $table->string('status', 20);
            $table->foreignId('provider_profile_change_request_id')->nullable()
                ->constrained(indexName: 'organization_documents_change_request_foreign')
                ->restrictOnDelete();
            $table->string('disk', 50);
            $table->string('path')->unique();
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['factory_id', 'type', 'status']);
            $table->index(['service_provider_id', 'type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_documents');
    }
};
