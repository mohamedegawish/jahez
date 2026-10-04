<?php

namespace App\Http\Requests\Api\V1;

use App\Models\FinancialPolicyVersion;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * What an amount comes to under one version's values, whatever its status (ADR-023), so
 * an approver sees the effect of a draft before deciding.
 */
class PreviewFinancialPolicyVersionRequest extends FormRequest
{
    public function authorize(): Response
    {
        /** @var FinancialPolicyVersion $version */
        $version = $this->route('financialPolicyVersion');

        return Gate::inspect('preview', $version);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ];
    }
}
