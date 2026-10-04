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
     * The record of what a factory and a provider agreed: created in the same transaction
     * as the factory's acceptance of the provider's offer (ADR-017). It is not a contract,
     * an invoice or a payment (OQ-15 to OQ-17). The terms are those of the accepted offer
     * version, which is append-only; the price is copied for billing. Append-only. The
     * concluding user is indexed, not a foreign key, so writing the record inside the
     * marketplace transaction locks no users rows (as ADR-012).
     *
     * Threads agreed before this migration get their agreement here, with no concluding
     * user and the time the thread became agreed.
     */
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_request_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('factory_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('catalog_service_id')->constrained()->restrictOnDelete();
            $table->foreignId('offer_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('price_amount', 14, 2);
            $table->char('currency', 3);
            $table->unsignedBigInteger('concluded_by_user_id')->nullable()->index();
            $table->timestamp('concluded_at');

            $table->index(['factory_id', 'id']);
            $table->index(['service_provider_id', 'id']);
        });

        DB::table('provider_requests')
            ->join('service_requests', 'service_requests.id', '=', 'provider_requests.service_request_id')
            ->join('offers', 'offers.id', '=', 'provider_requests.agreed_offer_id')
            ->where('provider_requests.status', 'agreed')
            ->orderBy('provider_requests.id')
            ->select([
                'provider_requests.id as provider_request_id',
                'provider_requests.service_request_id',
                'service_requests.factory_id',
                'provider_requests.service_provider_id',
                'service_requests.catalog_service_id',
                'offers.id as offer_id',
                'offers.price_amount',
                'offers.currency',
                'provider_requests.status_changed_at',
                'provider_requests.updated_at',
            ])
            ->get()
            ->each(fn (object $thread) => DB::table('agreements')->insert([
                'provider_request_id' => $thread->provider_request_id,
                'service_request_id' => $thread->service_request_id,
                'factory_id' => $thread->factory_id,
                'service_provider_id' => $thread->service_provider_id,
                'catalog_service_id' => $thread->catalog_service_id,
                'offer_id' => $thread->offer_id,
                'price_amount' => $thread->price_amount,
                'currency' => $thread->currency,
                'concluded_by_user_id' => null,
                'concluded_at' => $thread->status_changed_at ?? $thread->updated_at,
            ]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agreements');
    }
};
