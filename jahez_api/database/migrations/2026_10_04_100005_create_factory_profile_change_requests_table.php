<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A factory member's request to change its recorded legal information (ADR-020):
     * the legal name, the registration numbers and the registration documents, once
     * they hold a value. Mirrors provider_profile_change_requests (ADR-019): nothing
     * changes until an IMC administrator approves; `is_open` allows one pending request
     * per factory.
     */
    public function up(): void
    {
        Schema::create('factory_profile_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->index();
            $table->boolean('is_open')->nullable();
            $table->json('changes')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();

            $table->unique(['factory_id', 'is_open'], 'factory_change_requests_one_open');
        });

        Schema::table('organization_documents', function (Blueprint $table) {
            $table->foreignId('factory_profile_change_request_id')->nullable()->after('provider_profile_change_request_id')
                ->constrained(indexName: 'organization_documents_factory_change_request_foreign')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organization_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('factory_profile_change_request_id');
        });

        Schema::dropIfExists('factory_profile_change_requests');
    }
};
