<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesRecommendedFilter;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Filters for the service catalog. Any signed-in account may read the catalog;
 * filter[eligible] is meaningful only for a factory member (services that at least one
 * provider eligible for their factory offers), and so is filter[recommended] (services
 * the readiness roadmap recommends for their factory's current category, ADR-018).
 */
class ListCatalogServicesRequest extends FormRequest
{
    use ValidatesRecommendedFilter;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:category,eligible,recommended'],
            'filter.category' => ['sometimes', 'string', Rule::exists('service_categories', 'code')],
            'filter.eligible' => ['sometimes', 'boolean'],
            'filter.recommended' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();

                if ($this->boolean('filter.eligible') && ! ($user instanceof User && $user->factory_id !== null)) {
                    $validator->errors()->add('filter.eligible', 'Only factory members can list the services eligible for their factory.');
                }

                $this->validateRecommendedFilter($validator);
            },
        ];
    }
}
