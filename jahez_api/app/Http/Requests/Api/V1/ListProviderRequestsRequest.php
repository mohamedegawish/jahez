<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProviderRequestStatus;
use App\Models\ProviderRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Listing provider requests: a provider's inbox, a factory's sent requests per provider,
 * or all of them for IMC administrators. `search` matches the request title, the factory
 * or the provider name; `filter[unread]` keeps the threads with messages the caller has
 * not read (parties only); `sort` is allow-listed.
 */
class ListProviderRequestsRequest extends ListRequest
{
    public const SORTS = ['newest', 'oldest', 'recent_activity'];

    public function authorize(): bool
    {
        return Gate::allows('viewAny', ProviderRequest::class);
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
            'sort' => ['sometimes', 'string', Rule::in(self::SORTS)],
            'filter' => ['sometimes', 'array:status,service_request,unread,service'],
            'filter.status' => ['sometimes', 'string', Rule::enum(ProviderRequestStatus::class)],
            'filter.service_request' => ['sometimes', 'integer', 'min:1'],
            'filter.unread' => ['sometimes', 'boolean'],
            'filter.service' => ['sometimes', 'string', 'max:100'],
        ];
    }

    public function sort(): string
    {
        return $this->string('sort', 'newest')->toString();
    }
}
