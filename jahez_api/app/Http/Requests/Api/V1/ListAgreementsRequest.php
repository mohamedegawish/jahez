<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AgreementReviewStatus;
use App\Models\Agreement;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Listing agreements: a party's own, or all of them for IMC. `filter[review_status]`
 * selects by IMC's review (ADR-020): the IMC queue is `pending`.
 */
class ListAgreementsRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Agreement::class);
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
            'search' => ['sometimes', 'string', 'max:100'],
            'filter' => ['sometimes', 'array:review_status,contract_status'],
            'filter.review_status' => ['sometimes', 'string', Rule::enum(AgreementReviewStatus::class)],
            'filter.contract_status' => ['sometimes', 'string', Rule::in(['none', 'draft'])],
        ];
    }
}
