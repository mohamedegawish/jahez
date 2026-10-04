<?php

namespace App\Billing;

use App\Enums\FinancialPolicyKind;
use App\Enums\InvoiceIssuer;
use App\Models\Contract;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The values each kind of financial policy holds, and how they are validated (ADR-023).
 * Every value is required: the platform supplies none, so an incomplete policy cannot be
 * submitted. Where only one method exists today (a percentage revenue share on the
 * subtotal, due dates counted in days after issue), the method is still stored, so a
 * later method is an addition and never changes what an approved version meant.
 * Percentages and amounts are decimal strings, never JSON numbers, so no float enters.
 */
final class PolicyParameters
{
    public const INVOICE_TYPE_AGREEMENT_SERVICE = 'agreement_service';

    public const PAYER_FACTORY = 'factory';

    public const CURRENCY_EGP = 'EGP';

    public const PARTIES = ['factory', 'service_provider', 'imc'];

    /**
     * Validation rules for the parameters of a kind, keyed under `parameters.` as the API
     * receives them.
     *
     * @return array<string, mixed>
     */
    public static function rules(FinancialPolicyKind $kind): array
    {
        $rules = ['parameters' => ['required', 'array']];
        foreach (self::kindRules($kind) as $key => $rule) {
            $rules["parameters.{$key}"] = array_map(
                fn (mixed $part): mixed => is_string($part) ? preg_replace('/^(required_if|prohibited_unless):/', '$1:parameters.', $part) : $part,
                $rule,
            );
        }

        return $rules;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function kindRules(FinancialPolicyKind $kind): array
    {
        $percent = ['required', 'string', self::percentRule()];

        return match ($kind) {
            FinancialPolicyKind::RevenueShare => [
                'method' => ['required', 'string', Rule::in(['percentage'])],
                'rate_percent' => $percent,
                'base' => ['required', 'string', Rule::in(['subtotal_before_tax'])],
            ],
            FinancialPolicyKind::Tax => [
                'prices_include_tax' => ['required', 'boolean:strict'],
                'taxes' => ['present', 'array', 'max:5'],
                'taxes.*' => ['array:code,name_ar,rate_percent'],
                'taxes.*.code' => ['required', 'string', 'alpha_dash:ascii', 'max:30', 'distinct'],
                'taxes.*.name_ar' => ['required', 'string', 'max:120'],
                'taxes.*.rate_percent' => $percent,
                'fees' => ['present', 'array', 'max:5'],
                'fees.*' => ['array:code,name_ar,calculation,amount,rate_percent,taxable'],
                'fees.*.code' => ['required', 'string', 'alpha_dash:ascii', 'max:30', 'distinct'],
                'fees.*.name_ar' => ['required', 'string', 'max:120'],
                'fees.*.calculation' => ['required', 'string', Rule::in(['fixed', 'percentage'])],
                'fees.*.amount' => ['required_if:fees.*.calculation,fixed', 'prohibited_unless:fees.*.calculation,fixed', 'nullable', 'string', self::amountRule()],
                'fees.*.rate_percent' => ['required_if:fees.*.calculation,percentage', 'prohibited_unless:fees.*.calculation,percentage', 'nullable', 'string', self::percentRule()],
                'fees.*.taxable' => ['required', 'boolean:strict'],
            ],
            FinancialPolicyKind::Invoicing => [
                'issuer' => ['required', 'string', Rule::enum(InvoiceIssuer::class)],
                'payer' => ['required', 'string', Rule::in([self::PAYER_FACTORY])],
                'currency' => ['required', 'string', Rule::in([self::CURRENCY_EGP])],
                'number_prefix' => ['required', 'string', 'regex:/^[A-Za-z0-9\/-]{1,20}$/'],
                'number_padding' => ['required', 'integer:strict', 'between:4,10'],
                'invoice_types' => ['required', 'array', 'min:1'],
                'invoice_types.*' => ['string', 'distinct', Rule::in([self::INVOICE_TYPE_AGREEMENT_SERVICE])],
                'manual_payments_allowed' => ['required', 'boolean:strict'],
                'requires_revenue_share' => ['required', 'boolean:strict'],
            ],
            FinancialPolicyKind::PaymentTerms => [
                'due_rule' => ['required', 'string', Rule::in(['days_after_issue'])],
                'due_days' => ['required', 'integer:strict', 'between:0,365'],
                'partial_payments_allowed' => ['required', 'boolean:strict'],
            ],
            FinancialPolicyKind::ContractTemplate => [
                'title_ar' => ['required', 'string', 'max:191'],
                'parties' => ['required', 'array', 'min:2', function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_array($value) && (! in_array('factory', $value, true) || ! in_array('service_provider', $value, true))) {
                        $fail('A contract template must name the factory and the service provider as parties.');
                    }
                }],
                'parties.*' => ['string', 'distinct', Rule::in(self::PARTIES)],
                'duration_months' => ['present', 'nullable', 'integer:strict', 'between:1,120'],
                'knowledge_transfer_min_trainees' => ['required', 'integer:strict', 'between:'.Contract::MIN_KNOWLEDGE_TRANSFER_TRAINEES.',50'],
                'clauses' => ['required', 'array', 'min:1', 'max:50'],
                'clauses.*' => ['array:heading_ar,body_ar'],
                'clauses.*.heading_ar' => ['required', 'string', 'max:191'],
                'clauses.*.body_ar' => ['required', 'string', 'max:10000'],
            ],
        };
    }

    /**
     * Validated parameters in their stored form: only the known keys, percentages and
     * amounts with two decimals ("14" is stored as "14.00").
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public static function normalize(FinancialPolicyKind $kind, array $parameters): array
    {
        $validated = Validator::make(['parameters' => $parameters], self::rules($kind))->after(function ($validator) use ($kind, $parameters): void {
            if ($kind === FinancialPolicyKind::Tax && is_array($parameters['taxes'] ?? null)) {
                $total = 0;
                foreach ($parameters['taxes'] as $tax) {
                    $total += is_array($tax) ? (Money::basisPoints($tax['rate_percent'] ?? null) ?? 0) : 0;
                }
                if ($total > 10_000) {
                    $validator->errors()->add('parameters.taxes', 'The tax rates together may not exceed 100%.');
                }
            }
        })->validate()['parameters'];

        return match ($kind) {
            FinancialPolicyKind::RevenueShare => [
                'method' => $validated['method'],
                'rate_percent' => self::percent($validated['rate_percent']),
                'base' => $validated['base'],
            ],
            FinancialPolicyKind::Tax => [
                'prices_include_tax' => $validated['prices_include_tax'],
                'taxes' => array_map(fn (array $tax): array => [
                    'code' => $tax['code'],
                    'name_ar' => $tax['name_ar'],
                    'rate_percent' => self::percent($tax['rate_percent']),
                ], array_values($validated['taxes'])),
                'fees' => array_map(fn (array $fee): array => [
                    'code' => $fee['code'],
                    'name_ar' => $fee['name_ar'],
                    'calculation' => $fee['calculation'],
                    'amount' => $fee['calculation'] === 'fixed' ? Money::fromMinor(Money::toMinor($fee['amount'])) : null,
                    'rate_percent' => $fee['calculation'] === 'percentage' ? self::percent($fee['rate_percent']) : null,
                    'taxable' => $fee['taxable'],
                ], array_values($validated['fees'])),
            ],
            FinancialPolicyKind::Invoicing => [
                'issuer' => $validated['issuer'],
                'payer' => $validated['payer'],
                'currency' => $validated['currency'],
                'number_prefix' => $validated['number_prefix'],
                'number_padding' => $validated['number_padding'],
                'invoice_types' => array_values($validated['invoice_types']),
                'manual_payments_allowed' => $validated['manual_payments_allowed'],
                'requires_revenue_share' => $validated['requires_revenue_share'],
            ],
            FinancialPolicyKind::PaymentTerms => [
                'due_rule' => $validated['due_rule'],
                'due_days' => $validated['due_days'],
                'partial_payments_allowed' => $validated['partial_payments_allowed'],
            ],
            FinancialPolicyKind::ContractTemplate => [
                'title_ar' => $validated['title_ar'],
                'parties' => array_values($validated['parties']),
                'duration_months' => $validated['duration_months'],
                'knowledge_transfer_min_trainees' => $validated['knowledge_transfer_min_trainees'],
                'clauses' => array_map(fn (array $clause): array => [
                    'heading_ar' => $clause['heading_ar'],
                    'body_ar' => $clause['body_ar'],
                ], array_values($validated['clauses'])),
            ],
        };
    }

    private static function percent(string $value): string
    {
        return Money::fromMinor((int) Money::basisPoints($value));
    }

    private static function percentRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (Money::basisPoints($value) === null) {
                $fail('The :attribute must be a percentage from 0 to 100 with at most two decimals, written as a string.');
            }
        };
    }

    private static function amountRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || preg_match('/^\d{1,12}(\.\d{1,2})?$/', $value) !== 1) {
                $fail('The :attribute must be a non-negative amount with at most two decimals, written as a string.');
            }
        };
    }
}
