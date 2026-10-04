<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProviderApprovalStatus;
use App\Enums\ServiceListingStatus;
use App\Models\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Filters for the IMC list of providers, including the review queue
 * (`filter[approval_status]=pending`) and providers with listings waiting for review
 * (`filter[listing_status]=pending`, ADR-021). Every filter and sort is allow-listed;
 * values are bound as query parameters. Authorization runs first, so members get 403
 * before validation.
 */
class ListServiceProvidersRequest extends ListRequest
{
    /**
     * Allowed sort keys and the column and direction each one means.
     */
    private const SORTS = [
        'newest' => ['created_at', 'desc'],
        'oldest' => ['created_at', 'asc'],
        'name' => ['name', 'asc'],
        'recently_decided' => ['approval_changed_at', 'desc'],
    ];

    public function authorize(): bool
    {
        return Gate::allows('viewAny', ServiceProvider::class);
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
            'filter' => ['sometimes', 'array:approval_status,sector,service,category,listing_status'],
            'filter.approval_status' => ['sometimes', 'string', Rule::enum(ProviderApprovalStatus::class)],
            'filter.sector' => ['sometimes', 'string', Rule::exists('sectors', 'code')],
            'filter.service' => ['sometimes', 'string', Rule::exists('catalog_services', 'code')],
            'filter.category' => ['sometimes', 'string', Rule::exists('service_categories', 'code')],
            'filter.listing_status' => ['sometimes', 'string', Rule::enum(ServiceListingStatus::class)],
            'sort' => ['sometimes', 'string', Rule::in(array_keys(self::SORTS))],
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }

    /**
     * The requested order, or null for the default (by id).
     *
     * @return array{0: string, 1: string}|null
     */
    public function sortColumnAndDirection(): ?array
    {
        return self::SORTS[$this->string('sort')->toString()] ?? null;
    }
}
