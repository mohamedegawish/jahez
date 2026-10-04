<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\FinancialPolicyKind;
use App\Enums\FinancialPolicyScope;
use App\Models\FinancialPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Listing financial policies (ADR-023), by kind and scope type.
 */
class ListFinancialPoliciesRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', FinancialPolicy::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'filter' => ['sometimes', 'array:kind,scope_type'],
            'filter.kind' => ['sometimes', 'string', Rule::enum(FinancialPolicyKind::class)],
            'filter.scope_type' => ['sometimes', 'string', Rule::enum(FinancialPolicyScope::class)],
        ];
    }
}
