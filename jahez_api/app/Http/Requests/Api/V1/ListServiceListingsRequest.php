<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Permission;
use App\Enums\ProviderApprovalStatus;
use App\Enums\ServiceListingStatus;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Listing service listings (ADR-020): factory members, provider members and IMC
 * administrators who may see providers. `filter[recommended]` is for factory members
 * with a readiness assessment, like the catalog's filter (422 otherwise).
 */
class ListServiceListingsRequest extends ListRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && (
            $user->factory_id !== null
            || $user->service_provider_id !== null
            || $user->hasPermission(Permission::ServiceProvidersViewAny)
        );
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
            'sort' => ['sometimes', 'string', Rule::in(['catalog', 'newest'])],
            'filter' => ['sometimes', 'array:category,service,recommended,promoted,approval_status,provider,listing_status'],
            'filter.listing_status' => ['sometimes', 'string', Rule::enum(ServiceListingStatus::class)],
            'filter.category' => ['sometimes', 'string', 'max:100'],
            'filter.service' => ['sometimes', 'string', 'max:100'],
            'filter.recommended' => ['sometimes', 'boolean'],
            'filter.promoted' => ['sometimes', 'boolean'],
            'filter.approval_status' => ['sometimes', 'string', Rule::enum(ProviderApprovalStatus::class)],
            'filter.provider' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('filter.recommended')) {
                    return;
                }

                $user = $this->user();
                $factory = $user instanceof User ? $user->industrialFactory : null;

                if ($factory === null) {
                    $validator->errors()->add('filter.recommended', 'Recommendations are available to factory members only.');
                } elseif (! $factory->readinessAssessments()->exists()) {
                    $validator->errors()->add('filter.recommended', 'Complete the digital readiness assessment to see recommended services.');
                }
            },
        ];
    }
}
