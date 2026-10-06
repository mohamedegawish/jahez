<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What the factory chose from the provider's listing when it sent the request from its
     * cart (ADR-027): a copy of the package as listed then (name, prices, users), the
     * billing period and the number of users wanted. A copy, so a later change of the
     * provider's packages never rewrites a sent request. Null for a request sent without a
     * cart choice. Informational: the provider still answers with offers.
     */
    public function up(): void
    {
        Schema::table('provider_requests', function (Blueprint $table) {
            $table->json('selection')->nullable()->after('agreed_offer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('provider_requests', function (Blueprint $table) {
            $table->dropColumn('selection');
        });
    }
};
