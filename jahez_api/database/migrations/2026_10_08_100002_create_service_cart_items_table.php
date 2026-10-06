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
     * A factory member's cart (ADR-027, owner request 2026-10-06): provider listings the
     * member intends to request, each with an optional package, billing period and number
     * of users. Checking out sends one service request per service to the providers chosen
     * for it; nothing is paid. An item goes when its listing goes (the provider stops
     * offering the service); its package becomes null when the provider replaces its
     * packages, and the member chooses again.
     */
    public function up(): void
    {
        Schema::create('service_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('catalog_service_id');
            $table->unsignedBigInteger('service_provider_id');
            $table->foreignId('service_listing_package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('billing_period', 10)->nullable();
            $table->unsignedInteger('users_count')->nullable();
            $table->timestamps();

            $table->foreign(['catalog_service_id', 'service_provider_id'], 'cart_items_listing_foreign')
                ->references(['catalog_service_id', 'service_provider_id'])
                ->on('catalog_service_service_provider')
                ->cascadeOnDelete();
            $table->unique(['user_id', 'catalog_service_id', 'service_provider_id'], 'cart_items_user_listing_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE service_cart_items ADD CONSTRAINT service_cart_items_choice_check
            CHECK ((billing_period IS NULL OR billing_period IN ('monthly', 'annual'))
                AND (users_count IS NULL OR users_count >= 1))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_cart_items');
    }
};
