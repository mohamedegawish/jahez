<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Permissions granted to one IMC administrator rather than to the whole role (ADR-023).
     * Only the permissions App\Enums\Permission::grantedIndividually() lists can be
     * granted here: editing and approving financial policies and recording manual
     * payments, so the people who prepare a rule and the people who approve it can be
     * different. A revoked grant is deleted; the audit log keeps the history.
     */
    public function up(): void
    {
        Schema::create('user_permission_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('permission', 60);
            $table->unsignedBigInteger('granted_by_user_id')->nullable();
            $table->text('reason');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'permission']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_permission_grants');
    }
};
