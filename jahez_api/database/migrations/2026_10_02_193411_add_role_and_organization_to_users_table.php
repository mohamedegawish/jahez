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
     * Every user has exactly the organization link its role requires; the CHECK
     * constraint enforces this in the database (MySQL 8.0.16+ / MariaDB 10.2+).
     *
     * Existing users have no role to infer, and MySQL DDL is not transactional, so the
     * migration refuses to start (before changing anything) if the table has rows.
     * No environment had users before this migration; rolling it back drops the roles.
     */
    public function up(): void
    {
        if (DB::table('users')->exists()) {
            throw new RuntimeException(
                'The users table already has rows, which have no role to assign. Empty it (or add role and organization columns '
                .'and backfill them manually) before running this migration. See docs/decisions/ADR-006-roles-and-permissions.md.'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 30)->after('password');
            $table->foreignId('factory_id')->nullable()->after('role')->constrained()->restrictOnDelete();
            $table->foreignId('service_provider_id')->nullable()->after('factory_id')->constrained()->restrictOnDelete();
            $table->timestamp('deactivated_at')->nullable()->after('remember_token');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT users_role_organization_check CHECK (
                (role = 'imc_admin' AND factory_id IS NULL AND service_provider_id IS NULL)
                OR (role = 'factory_member' AND factory_id IS NOT NULL AND service_provider_id IS NULL)
                OR (role = 'provider_member' AND service_provider_id IS NOT NULL AND factory_id IS NULL)
            )
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_organization_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_provider_id');
            $table->dropConstrainedForeignId('factory_id');
            $table->dropColumn(['role', 'deactivated_at']);
        });
    }
};
