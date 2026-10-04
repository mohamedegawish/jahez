<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProfileChangeRequestStatus;
use App\Models\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The IMC queue of provider change requests (ADR-019). `filter[status]` is allow-listed
 * and defaults to pending. Authorization runs first, so members get 403 before
 * validation.
 */
class ListProviderChangeRequestsRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('reviewChangeRequests', ServiceProvider::class);
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
            'filter' => ['sometimes', 'array:status'],
            'filter.status' => ['sometimes', 'string', Rule::enum(ProfileChangeRequestStatus::class)],
        ];
    }

    public function status(): ProfileChangeRequestStatus
    {
        return ProfileChangeRequestStatus::tryFrom((string) $this->input('filter.status')) ?? ProfileChangeRequestStatus::Pending;
    }
}
