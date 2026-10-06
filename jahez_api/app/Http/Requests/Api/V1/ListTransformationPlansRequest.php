<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\TransformationPlanStatus;
use App\Models\TransformationPlan;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Filters for the list of transformation plans (ADR-025): IMC sees every plan; a factory
 * member sees only its own factory's published plans, whatever the filters say. Every
 * filter and sort is allow-listed.
 */
class ListTransformationPlansRequest extends ListRequest
{
    /**
     * Allowed sort keys and the column and direction each one means.
     */
    private const SORTS = [
        'newest' => ['created_at', 'desc'],
        'recently_changed' => ['updated_at', 'desc'],
    ];

    public function authorize(): Response
    {
        return Gate::inspect('viewAny', TransformationPlan::class);
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
            'filter' => ['sometimes', 'array:status,factory'],
            'filter.status' => ['sometimes', 'string', Rule::enum(TransformationPlanStatus::class)],
            'filter.factory' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'string', Rule::in(array_keys(self::SORTS))],
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }

    /**
     * The requested order, or null for the default (newest first).
     *
     * @return array{0: string, 1: string}|null
     */
    public function sortColumnAndDirection(): ?array
    {
        return self::SORTS[$this->string('sort')->toString()] ?? null;
    }
}
