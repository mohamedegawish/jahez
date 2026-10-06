<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The transformation plan item a service request was sent for (ADR-025), so the plan
     * shows the request's real status. Optional: requests outside a plan are unchanged.
     * Several requests may point to one item over time (after a cancellation, or after IMC
     * rejected the agreement); at most one of them is live, which the API checks with the
     * item row locked.
     */
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->foreignId('transformation_plan_item_id')->nullable()->after('catalog_service_id')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transformation_plan_item_id');
        });
    }
};
