<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ContractStatus;
use App\Models\Contract;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Listing contract drafts: a party's own, or all of them (status only) for IMC.
 */
class ListContractsRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Contract::class);
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
            'filter' => ['sometimes', 'array:status,agreement'],
            'filter.status' => ['sometimes', 'string', Rule::enum(ContractStatus::class)],
            'filter.agreement' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
