<?php

namespace App\Billing;

use App\Enums\AgreementReviewStatus;
use App\Enums\FinancialPolicyKind;
use App\Enums\FinancialPolicyVersionStatus;
use App\Enums\InvoiceIssuer;
use App\Models\Agreement;
use App\Models\FinancialPolicyVersion;
use Carbon\CarbonImmutable;

/**
 * Which billing operations the approved financial policies allow (ADR-017, ADR-023).
 * Every rule comes from an approved policy version in the database; nothing has a
 * default. An operation whose policy is missing is refused with 409
 * `policy_not_configured`, naming the open question and, in Arabic, what is missing.
 */
final class BillingPolicy
{
    /**
     * Who issues invoices for the agreement under the invoicing policy in effect today, or
     * null while none applies.
     */
    public static function issuerFor(Agreement $agreement): ?InvoiceIssuer
    {
        try {
            $invoicing = PolicyResolver::resolve(FinancialPolicyKind::Invoicing, PolicyContext::forAgreement($agreement), PolicyCalendar::today());
        } catch (PolicyNotConfiguredException) {
            return null;
        }

        return $invoicing === null ? null : InvoiceIssuer::tryFrom((string) $invoicing->parameters['issuer']);
    }

    /**
     * The policies an invoice is issued under, all resolved for the agreement on the day,
     * or one refusal listing everything that is missing.
     *
     * @return array{invoicing: FinancialPolicyVersion, tax: FinancialPolicyVersion, payment_terms: FinancialPolicyVersion, revenue_share: FinancialPolicyVersion|null}
     */
    public static function issuingPolicies(Agreement $agreement, CarbonImmutable $day, bool $lock = false): array
    {
        $context = PolicyContext::forAgreement($agreement);
        $resolved = [];
        $missing = [];

        foreach ([FinancialPolicyKind::Invoicing, FinancialPolicyKind::Tax, FinancialPolicyKind::PaymentTerms, FinancialPolicyKind::RevenueShare] as $kind) {
            $resolved[$kind->value] = PolicyResolver::resolve($kind, $context, $day, $lock);
        }

        foreach ([FinancialPolicyKind::Invoicing, FinancialPolicyKind::Tax, FinancialPolicyKind::PaymentTerms] as $kind) {
            if ($resolved[$kind->value] === null) {
                $missing[] = $kind;
            }
        }
        if ($resolved['invoicing'] !== null && $resolved['invoicing']->parameters['requires_revenue_share'] === true && $resolved['revenue_share'] === null) {
            $missing[] = FinancialPolicyKind::RevenueShare;
        }

        if ($missing !== []) {
            throw self::missing($missing, $day, 'issue this invoice', 'إصدار هذه الفاتورة');
        }

        /** @var array{invoicing: FinancialPolicyVersion, tax: FinancialPolicyVersion, payment_terms: FinancialPolicyVersion, revenue_share: FinancialPolicyVersion|null} $resolved */
        return $resolved;
    }

    /**
     * One refusal for several missing policies.
     *
     * @param  non-empty-list<FinancialPolicyKind>  $kinds
     */
    public static function missing(array $kinds, CarbonImmutable $day, string $operation, string $operationAr): PolicyNotConfiguredException
    {
        if (count($kinds) === 1) {
            return PolicyResolver::missing($kinds[0], $day, $operation, $operationAr);
        }

        $labels = implode('، ', array_map(fn (FinancialPolicyKind $kind): string => "«{$kind->labelAr()}»", $kinds));
        $values = array_map(fn (FinancialPolicyKind $kind): string => $kind->value, $kinds);
        $questions = implode(', ', array_values(array_unique(array_map(fn (FinancialPolicyKind $kind): string => $kind->decisionNeeded(), $kinds))));

        return new PolicyNotConfiguredException(
            'No approved '.implode(', ', $values)." policy applies on {$day->toDateString()}, so it is not possible to {$operation}.",
            $questions,
            "لا توجد سياسات معتمدة وسارية بتاريخ {$day->toDateString()} من الأنواع التالية: {$labels}، لذلك لا يمكن {$operationAr}. يلزم إعداد كل سياسة واعتمادها من «الإعدادات المالية والتعاقدية».",
            $values,
        );
    }

    /**
     * For each financial operation on the agreement: whether it is possible now and, if
     * not, every reason, in Arabic, with the decision each reason waits for.
     *
     * @return array<string, array{available: bool, reasons: list<array{code: string, message_ar: string, decision_needed: string|null}>}>
     */
    public static function readiness(Agreement $agreement): array
    {
        $day = PolicyCalendar::today();
        $approval = self::approvalReason($agreement);
        $context = PolicyContext::forAgreement($agreement);

        $policyReason = function (FinancialPolicyKind $kind) use ($context, $day): ?array {
            try {
                $version = PolicyResolver::resolve($kind, $context, $day);
            } catch (PolicyNotConfiguredException $exception) {
                return ['code' => 'policy_conflict', 'message_ar' => (string) $exception->reasonAr, 'decision_needed' => $exception->decisionNeeded];
            }

            return $version !== null ? null : [
                'code' => "missing_{$kind->value}_policy",
                'message_ar' => "لا توجد سياسة «{$kind->labelAr()}» معتمدة وسارية اليوم تنطبق على هذه الاتفاقية.",
                'decision_needed' => $kind->decisionNeeded(),
            ];
        };

        $invoicingReason = $policyReason(FinancialPolicyKind::Invoicing);
        $issuingReasons = self::present(
            $approval,
            $invoicingReason,
            $policyReason(FinancialPolicyKind::Tax),
            $policyReason(FinancialPolicyKind::PaymentTerms),
        );

        $invoicing = $invoicingReason === null ? self::quietly(fn () => PolicyResolver::resolve(FinancialPolicyKind::Invoicing, $context, $day)) : null;
        if ($invoicing !== null && $invoicing->parameters['requires_revenue_share'] === true && ($share = $policyReason(FinancialPolicyKind::RevenueShare)) !== null) {
            $issuingReasons[] = $share;
        }

        $gateway = PaymentGatewayManager::isConfigured() ? null : [
            'code' => 'payment_gateway_not_configured',
            'message_ar' => 'لم يُعتمد أي بوابة دفع إلكتروني بعد، لذلك لا يمكن السداد إلكترونيًا.',
            'decision_needed' => 'OQ-16',
        ];

        return [
            'contract_drafting' => self::operation(self::present($approval)),
            'invoice_drafting' => self::operation(self::present($approval, $invoicingReason)),
            'invoice_issuing' => self::operation($issuingReasons),
            'gateway_payment' => self::operation(self::present($gateway)),
            'contract_signature' => self::operation([[
                'code' => 'signing_not_approved',
                'message_ar' => 'لم يُعتمد إجراء التوقيع ولا الصفة القانونية للعقود بعد، لذلك تبقى العقود مسودات غير ملزمة.',
                'decision_needed' => 'OQ-17',
            ]]),
            'payouts' => self::operation([[
                'code' => 'payouts_not_decided',
                'message_ar' => 'لم تُحدَّد تدفقات الأموال ولا آلية تحويل المستحقات، لذلك لا تُصرف أي مبالغ.',
                'decision_needed' => 'OQ-15, OQ-16',
            ]]),
        ];
    }

    /**
     * Which billing operations an approved policy in effect today makes possible
     * anywhere on the platform. Whether one is possible for a given agreement depends on
     * its scope: see readiness().
     *
     * @return array<string, array{available: bool, decision_needed: string|null}>
     */
    public static function summary(): array
    {
        $today = PolicyCalendar::today()->toDateString();
        $inEffect = FinancialPolicyVersion::query()
            ->join('financial_policies', 'financial_policies.id', '=', 'financial_policy_versions.financial_policy_id')
            ->whereIn('financial_policy_versions.status', FinancialPolicyVersionStatus::resolvable())
            ->whereDate('financial_policy_versions.effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('financial_policy_versions.effective_to')->orWhereDate('financial_policy_versions.effective_to', '>=', $today))
            ->distinct()
            ->pluck('financial_policies.kind')
            ->map(fn (mixed $kind): string => (string) $kind)
            ->all();
        $has = fn (FinancialPolicyKind $kind): bool => in_array($kind->value, $inEffect, true);
        $entry = fn (bool $available, string $question): array => ['available' => $available, 'decision_needed' => $available ? null : $question];

        return [
            'invoice_drafting' => $entry($has(FinancialPolicyKind::Invoicing), 'OQ-16'),
            'invoice_issuing' => $entry($has(FinancialPolicyKind::Invoicing) && $has(FinancialPolicyKind::Tax) && $has(FinancialPolicyKind::PaymentTerms), 'OQ-16'),
            'revenue_share' => $entry($has(FinancialPolicyKind::RevenueShare), 'OQ-15'),
            'contract_templates' => $entry($has(FinancialPolicyKind::ContractTemplate), 'OQ-17'),
            'payments' => $entry(PaymentGatewayManager::isConfigured(), 'OQ-16'),
            'refund_initiation' => ['available' => false, 'decision_needed' => 'OQ-16'],
            'payouts' => ['available' => false, 'decision_needed' => 'OQ-15, OQ-16'],
        ];
    }

    /**
     * @return array{code: string, message_ar: string, decision_needed: string|null}|null
     */
    private static function approvalReason(Agreement $agreement): ?array
    {
        if (! (bool) config('jahez.agreements.imc_approval_required', true)) {
            return null;
        }

        return match ($agreement->reviewStatus()) {
            AgreementReviewStatus::Approved => null,
            AgreementReviewStatus::Pending => ['code' => 'agreement_awaiting_imc_review', 'message_ar' => 'الاتفاقية بانتظار اعتماد مركز تحديث الصناعة.', 'decision_needed' => null],
            default => ['code' => 'agreement_rejected', 'message_ar' => 'رفض مركز تحديث الصناعة هذه الاتفاقية.', 'decision_needed' => null],
        };
    }

    /**
     * @param  list<array{code: string, message_ar: string, decision_needed: string|null}>  $reasons
     * @return array{available: bool, reasons: list<array{code: string, message_ar: string, decision_needed: string|null}>}
     */
    private static function operation(array $reasons): array
    {
        return ['available' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * The reasons that apply, in order.
     *
     * @param  array{code: string, message_ar: string, decision_needed: string|null}|null  ...$reasons
     * @return list<array{code: string, message_ar: string, decision_needed: string|null}>
     */
    private static function present(?array ...$reasons): array
    {
        return array_values(array_filter($reasons, fn (?array $reason): bool => $reason !== null));
    }

    /**
     * @param  callable(): ?FinancialPolicyVersion  $resolve
     */
    private static function quietly(callable $resolve): ?FinancialPolicyVersion
    {
        try {
            return $resolve();
        } catch (PolicyNotConfiguredException) {
            return null;
        }
    }
}
