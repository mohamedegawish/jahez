<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesPolicyVersionDraft;
use App\Models\FinancialPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Draft the next version of a policy (ADR-023), for example a new rate from a date.
 */
class StoreFinancialPolicyVersionRequest extends FormRequest
{
    use ValidatesPolicyVersionDraft;

    public function authorize(): Response
    {
        return Gate::inspect('draft', $this->policy());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->draftRules($this->policy()->kind);
    }

    public function policy(): FinancialPolicy
    {
        /** @var FinancialPolicy $policy */
        $policy = $this->route('financialPolicy');

        return $policy;
    }
}
