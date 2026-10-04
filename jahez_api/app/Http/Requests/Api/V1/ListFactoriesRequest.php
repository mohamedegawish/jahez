<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\FactoryApprovalStatus;
use App\Enums\ReadinessCategoryCode;
use App\Models\Factory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Filters for the IMC list of factories, including the review queue
 * (`filter[approval_status]=pending`, ADR-021) and the current readiness category
 * (`filter[readiness]`, a category code or `none` for factories never assessed). Every
 * filter and sort is allow-listed; values are bound as query parameters. Authorization
 * runs first, so members get 403 before validation.
 */
class ListFactoriesRequest extends ListRequest
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
        return Gate::allows('viewAny', Factory::class);
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
            'filter' => ['sometimes', 'array:sector,size,approval_status,readiness'],
            'filter.sector' => ['sometimes', 'string', Rule::exists('sectors', 'code')],
            'filter.size' => ['sometimes', 'string', Rule::in(array_keys((array) config('jahez.factories.sizes')))],
            'filter.approval_status' => ['sometimes', 'string', Rule::enum(FactoryApprovalStatus::class)],
            'filter.readiness' => ['sometimes', 'string', Rule::in(['none', ...array_column(ReadinessCategoryCode::cases(), 'value')])],
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
