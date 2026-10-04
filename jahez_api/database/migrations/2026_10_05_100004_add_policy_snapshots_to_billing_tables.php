<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * References from agreements, contracts and invoices to the exact policy versions they
     * were made under, and the values calculated from them (ADR-023).
     *
     * `policy_basis` tells the two kinds of record apart: `legacy` for every row that
     * existed before policies were managed in the database (the column default, so the
     * existing rows are marked without inventing anything), `policy` for every row made
     * since, whose version references are then authoritative (NULL meaning no approved
     * policy applied). Nothing is recalculated for legacy rows.
     *
     * Payments gain the fields an authorised manual payment entry needs: the method, who
     * recorded it, the date the money was received and the evidence reference (stored in
     * `gateway_reference`, unique per method).
     */
    public function up(): void
    {
        Schema::table('agreements', function (Blueprint $table) {
            $table->string('policy_basis', 20)->default('legacy')->after('currency');
            $table->foreignId('revenue_share_policy_version_id')->nullable()->after('policy_basis')
                ->constrained('financial_policy_versions')->restrictOnDelete();
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->string('policy_basis', 20)->default('legacy')->after('notes');
            $table->foreignId('contract_template_version_id')->nullable()->after('policy_basis')
                ->constrained('financial_policy_versions')->restrictOnDelete();
            $table->json('terms_snapshot')->nullable()->after('contract_template_version_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('type', 40)->default('agreement_service')->after('number');
            $table->string('payer', 20)->nullable()->after('issuer');
            $table->string('policy_basis', 20)->default('legacy')->after('currency');
            $table->foreignId('invoicing_policy_version_id')->nullable()->after('policy_basis')
                ->constrained('financial_policy_versions')->restrictOnDelete();
            $table->foreignId('tax_policy_version_id')->nullable()->after('invoicing_policy_version_id')
                ->constrained('financial_policy_versions')->restrictOnDelete();
            $table->foreignId('payment_terms_policy_version_id')->nullable()->after('tax_policy_version_id')
                ->constrained('financial_policy_versions')->restrictOnDelete();
            $table->foreignId('revenue_share_policy_version_id')->nullable()->after('payment_terms_policy_version_id')
                ->constrained('financial_policy_versions')->restrictOnDelete();
            $table->decimal('fees_amount', 14, 2)->nullable()->after('subtotal_amount');
            $table->decimal('amount_paid', 14, 2)->default(0)->after('total_amount');
            $table->date('due_date')->nullable()->after('issued_at');
            $table->json('calculation')->nullable()->after('revenue_share_amount');

            $table->index(['status', 'due_date']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('method', 20)->default('gateway')->after('invoice_id');
            $table->unsignedBigInteger('recorded_by_user_id')->nullable()->after('initiated_by_user_id');
            $table->date('received_on')->nullable()->after('recorded_by_user_id');
            $table->text('evidence_note')->nullable()->after('received_on');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['method', 'recorded_by_user_id', 'received_on', 'evidence_note']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_date']);
            $table->dropConstrainedForeignId('invoicing_policy_version_id');
            $table->dropConstrainedForeignId('tax_policy_version_id');
            $table->dropConstrainedForeignId('payment_terms_policy_version_id');
            $table->dropConstrainedForeignId('revenue_share_policy_version_id');
            $table->dropColumn(['type', 'payer', 'policy_basis', 'fees_amount', 'amount_paid', 'due_date', 'calculation']);
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_template_version_id');
            $table->dropColumn(['policy_basis', 'terms_snapshot']);
        });

        Schema::table('agreements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revenue_share_policy_version_id');
            $table->dropColumn('policy_basis');
        });
    }
};
