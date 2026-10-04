<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Administratively managed financial and contract policies (ADR-023). A policy is one
     * kind of rule (revenue share, taxes and fees, invoicing, payment terms, contract
     * template) for one scope (global, a sector, a catalog service or a service provider).
     * Its values live only in its versions; this row holds no number. `scope_key`
     * ("global", "sector:3") makes the kind and scope unique, since MySQL unique indexes
     * do not treat NULL scope ids as equal. Users are indexed, not foreign keys, as in the
     * audit log (ADR-012).
     */
    public function up(): void
    {
        Schema::create('financial_policies', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 30);
            $table->string('scope_type', 30);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('scope_key', 60);
            $table->string('name_ar', 191);
            $table->text('description_ar')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->index();
            $table->timestamps();

            $table->unique(['kind', 'scope_key']);
            $table->index(['scope_type', 'scope_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_policies');
    }
};
