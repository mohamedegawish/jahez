<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A provider's formal offer on one provider request, in numbered versions (ADR-015).
     * A revision is a new version; versions are never changed. The price is informational:
     * accepting an offer creates no contract, invoice or payment (OQ-15, OQ-16, OQ-17).
     * EGP is the only currency, by owner decision of 2026-10-03.
     *
     * provider_requests.agreed_offer_id records which version the factory accepted.
     */
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_request_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->text('scope');
            $table->text('deliverables');
            $table->unsignedSmallInteger('duration_days');
            $table->decimal('price_amount', 14, 2);
            $table->char('currency', 3);
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->unique(['provider_request_id', 'version']);
        });

        Schema::table('provider_requests', function (Blueprint $table) {
            $table->foreignId('agreed_offer_id')->nullable()->after('status_changed_at')->constrained('offers')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('provider_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agreed_offer_id');
        });

        Schema::dropIfExists('offers');
    }
};
