<?php

namespace App\Enums;

/**
 * The kinds of administratively managed financial and contract policy (ADR-023). Every
 * value a kind needs lives in an approved version; the application defines no default
 * for any of them.
 */
enum FinancialPolicyKind: string
{
    /** IMC's share of an invoice subtotal (OQ-15). Informational: nothing is paid out. */
    case RevenueShare = 'revenue_share';

    /** Taxes and fees on an invoice (OQ-16). */
    case Tax = 'tax';

    /** Who issues and who pays, numbering, invoice types, manual payments (OQ-16). */
    case Invoicing = 'invoicing';

    /** When an issued invoice falls due, and whether part payments are accepted (OQ-16). */
    case PaymentTerms = 'payment_terms';

    /** The clauses, parties and duration a contract draft is generated with (OQ-17). */
    case ContractTemplate = 'contract_template';

    public function labelAr(): string
    {
        return match ($this) {
            self::RevenueShare => 'حصة الوزارة من الإيرادات',
            self::Tax => 'الضرائب والرسوم',
            self::Invoicing => 'سياسة الفوترة',
            self::PaymentTerms => 'شروط السداد',
            self::ContractTemplate => 'نموذج العقد',
        };
    }

    /**
     * The open question whose answer the policy's values record.
     */
    public function decisionNeeded(): string
    {
        return match ($this) {
            self::RevenueShare => 'OQ-15',
            self::Tax, self::Invoicing, self::PaymentTerms => 'OQ-16',
            self::ContractTemplate => 'OQ-17',
        };
    }

    /**
     * The scopes a policy of this kind may be set for. Invoicing is platform-wide: who
     * issues invoices and how they are numbered cannot differ by service without a
     * decision that says so.
     *
     * @return list<FinancialPolicyScope>
     */
    public function allowedScopes(): array
    {
        return match ($this) {
            self::RevenueShare, self::PaymentTerms => FinancialPolicyScope::cases(),
            self::Tax, self::ContractTemplate => [FinancialPolicyScope::Global, FinancialPolicyScope::CatalogService],
            self::Invoicing => [FinancialPolicyScope::Global],
        };
    }
}
