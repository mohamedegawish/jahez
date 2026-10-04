<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Billing\PolicyParameters;
use App\Enums\FinancialPolicyKind;

/**
 * The fields of a financial policy version draft (ADR-023): its values for the policy's
 * kind, its effective period (business-calendar days) and why it is being made. The
 * lifecycle checks again that the start date has not passed.
 */
trait ValidatesPolicyVersionDraft
{
    /**
     * @return array<string, mixed>
     */
    protected function draftRules(?FinancialPolicyKind $kind, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $parameterRules = $kind === null ? ['parameters' => ['required', 'array']] : PolicyParameters::rules($kind);
        if ($partial && ! $this->has('parameters')) {
            $parameterRules = ['parameters' => ['sometimes', 'array']];
        }

        return [
            ...$parameterRules,
            'effective_from' => [$required, 'date_format:Y-m-d'],
            'effective_to' => [$partial ? 'sometimes' : 'present', 'nullable', 'date_format:Y-m-d', ...($this->filled('effective_from') ? ['after_or_equal:effective_from'] : [])],
            'change_reason' => [$required, 'string', 'min:3', 'max:2000'],
        ];
    }

    /**
     * @return array{parameters: array<string, mixed>, effective_from: string, effective_to: string|null, change_reason: string}
     */
    public function draft(): array
    {
        /** @var array<string, mixed> $parameters */
        $parameters = (array) $this->input('parameters', []);

        return [
            'parameters' => $parameters,
            'effective_from' => $this->string('effective_from')->toString(),
            'effective_to' => $this->filled('effective_to') ? $this->string('effective_to')->toString() : null,
            'change_reason' => $this->string('change_reason')->toString(),
        ];
    }
}
