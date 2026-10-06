<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The packages a provider lists for one of its services (ADR-027, owner request
     * 2026-10-06): a name and, each optional, a monthly price, an annual price (EGP,
     * informational) and the number of users the price covers. A package states at least
     * one price. The rows belong to the listing (the provider and catalog service pair) and
     * go with it when the provider stops offering the service. Changing them sends the
     * listing back to IMC review.
     */
    public function up(): void
    {
        Schema::create('service_listing_packages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('catalog_service_id');
            $table->unsignedBigInteger('service_provider_id');
            $table->unsignedSmallInteger('position');
            $table->string('name_ar', 120);
            $table->decimal('monthly_price', 14, 2)->nullable();
            $table->decimal('annual_price', 14, 2)->nullable();
            $table->unsignedInteger('users_count')->nullable();
            $table->timestamps();

            $table->foreign(['catalog_service_id', 'service_provider_id'], 'listing_packages_listing_foreign')
                ->references(['catalog_service_id', 'service_provider_id'])
                ->on('catalog_service_service_provider')
                ->cascadeOnDelete();
            $table->unique(['catalog_service_id', 'service_provider_id', 'position'], 'listing_packages_position_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE service_listing_packages ADD CONSTRAINT service_listing_packages_price_check
            CHECK ((monthly_price IS NOT NULL OR annual_price IS NOT NULL)
                AND (monthly_price IS NULL OR monthly_price >= 0)
                AND (annual_price IS NULL OR annual_price >= 0)
                AND (users_count IS NULL OR users_count >= 1))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_listing_packages');
    }
};
