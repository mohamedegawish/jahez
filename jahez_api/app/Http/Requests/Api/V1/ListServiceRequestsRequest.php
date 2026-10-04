<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProviderRequestStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Listing service requests: the factory's own, or all of them for IMC administrators.
 * `search` matches the title or a provider's name; `filter[thread_status]` keeps the
 * requests with at least one provider thread in that status.
 */
class ListServiceRequestsRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', ServiceRequest::class);
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
            'sort' => ['sometimes', 'string', Rule::in(['newest', 'oldest'])],
            'filter' => ['sometimes', 'array:status,service,thread_status'],
            'filter.service' => ['sometimes', 'string', 'max:100'],
            'filter.thread_status' => ['sometimes', 'string', Rule::enum(ProviderRequestStatus::class)],
            'filter.status' => ['sometimes', 'string', Rule::enum(ServiceRequestStatus::class)],
        ];
    }
}
