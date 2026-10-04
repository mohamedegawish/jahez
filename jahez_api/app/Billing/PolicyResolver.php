<?php

namespace App\Billing;

use App\Enums\FinancialPolicyKind;
use App\Enums\FinancialPolicyScope;
use App\Enums\FinancialPolicyVersionStatus;
use App\Models\FinancialPolicy;
use App\Models\FinancialPolicyVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds the approved policy version that applies to a context on a day (ADR-023).
 *
 * Candidates are the policies of the kind whose scope matches the context: the global
 * policy, a policy for one of the factory's sectors, for the catalog service, or for the
 * service provider. Of the versions in effect on the day, the most specific scope wins
 * (FinancialPolicyScope::precedence(), PROPOSED, OQ-47). Two sector policies that both
 * apply are a conflict nobody decided, so the operation is refused rather than one of
 * them picked.
 *
 * Inside a transaction that creates a record from the result, pass $lock: the candidate
 * policy rows are share-locked, so an approval of a new version of one of them (which
 * locks the policy row for update) waits until the record is committed, and the record
 * never refers to a version that was superseded in the meantime.
 */
final class PolicyResolver
{
    public static function resolve(FinancialPolicyKind $kind, PolicyContext $context, CarbonImmutable $day, bool $lock = false): ?FinancialPolicyVersion
    {
        $policies = FinancialPolicy::query()
            ->where('kind', $kind)
            ->where(function (Builder $query) use ($context): void {
                $query->where('scope_type', FinancialPolicyScope::Global);
                if ($context->sectorIds !== []) {
                    $query->orWhere(fn (Builder $sector) => $sector->where('scope_type', FinancialPolicyScope::Sector)->whereIn('scope_id', $context->sectorIds));
                }
                if ($context->catalogServiceId !== null) {
                    $query->orWhere(fn (Builder $service) => $service->where('scope_type', FinancialPolicyScope::CatalogService)->where('scope_id', $context->catalogServiceId));
                }
                if ($context->serviceProviderId !== null) {
                    $query->orWhere(fn (Builder $provider) => $provider->where('scope_type', FinancialPolicyScope::ServiceProvider)->where('scope_id', $context->serviceProviderId));
                }
            })
            ->when($lock, fn (Builder $query) => $query->sharedLock())
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        if ($policies->isEmpty()) {
            return null;
        }

        $date = $day->toDateString();
        $versions = FinancialPolicyVersion::query()
            ->whereIn('financial_policy_id', $policies->keys())
            ->whereIn('status', FinancialPolicyVersionStatus::resolvable())
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderBy('id')
            ->get();

        if ($versions->isEmpty()) {
            return null;
        }

        $precedence = fn (FinancialPolicyVersion $version): int => $policies->get($version->financial_policy_id)?->scope_type->precedence() ?? 0;
        $best = $versions->max($precedence);
        $winners = $versions->filter(fn (FinancialPolicyVersion $version): bool => $precedence($version) === $best)->values();

        if ($winners->count() > 1) {
            throw new PolicyNotConfiguredException(
                "More than one {$kind->value} policy of the same scope applies (versions {$winners->pluck('id')->implode(', ')}); the precedence between them is not decided.",
                'OQ-47',
                "تنطبق أكثر من سياسة معتمدة من نوع «{$kind->labelAr()}» على هذه العملية بنفس المستوى (أكثر من قطاع للمنشأة)، ولم يُحدَّد أيّها يُطبَّق، لذلك أُوقفت العملية.",
                [$kind->value],
            );
        }

        $winner = $winners->first();
        $winner?->setRelation('policy', $policies->get($winner->financial_policy_id));

        return $winner;
    }

    /**
     * The applicable version, or a refusal naming the missing policy in English (the API
     * message, OQ-23) and Arabic.
     *
     * @param  string  $operation  English, for the message: "issue this invoice"
     * @param  string  $operationAr  Arabic, for the explanation: "إصدار هذه الفاتورة"
     */
    public static function require(FinancialPolicyKind $kind, PolicyContext $context, CarbonImmutable $day, string $operation, string $operationAr, bool $lock = false): FinancialPolicyVersion
    {
        return self::resolve($kind, $context, $day, $lock) ?? throw self::missing($kind, $day, $operation, $operationAr);
    }

    public static function missing(FinancialPolicyKind $kind, CarbonImmutable $day, string $operation, string $operationAr): PolicyNotConfiguredException
    {
        $label = $kind->labelAr();
        $date = $day->toDateString();

        return new PolicyNotConfiguredException(
            "No approved {$kind->value} policy applies on {$date}, so it is not possible to {$operation}.",
            $kind->decisionNeeded(),
            "لا توجد سياسة «{$label}» معتمدة وسارية بتاريخ {$date} تنطبق على هذه العملية، لذلك لا يمكن {$operationAr}. يلزم أن يعدّ مسؤول مختص السياسة ويعتمدها مسؤول آخر من «الإعدادات المالية والتعاقدية».",
            [$kind->value],
        );
    }
}
