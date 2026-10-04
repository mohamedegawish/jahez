<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Permission;
use App\Enums\ReadinessCategoryCode;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Filters for the IMC list of submitted readiness assessments across factories, and the
 * period of the analytics (ADR-021). Dates are UTC days. Every filter and sort is
 * allow-listed; values are bound as query parameters. Authorization runs first.
 */
class ListReadinessAssessmentsRequest extends ListRequest
{
    /**
     * Allowed sort keys and the column and direction each one means.
     */
    private const SORTS = [
        'newest' => ['completed_at', 'desc'],
        'oldest' => ['completed_at', 'asc'],
        'score_desc' => ['total_score', 'desc'],
        'score_asc' => ['total_score', 'asc'],
    ];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasPermission(Permission::AssessmentsViewAny);
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
            'sort' => ['sometimes', 'string', Rule::in(array_keys(self::SORTS))],
            'filter' => ['sometimes', 'array:category,version,current,from,to,score_min,score_max,factory'],
            'filter.category' => ['sometimes', 'string', Rule::enum(ReadinessCategoryCode::class)],
            'filter.version' => ['sometimes', 'integer', 'min:1'],
            'filter.current' => ['sometimes', 'boolean'],
            'filter.from' => ['sometimes', 'date_format:Y-m-d'],
            'filter.to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('filter.from') ? ['after_or_equal:filter.from'] : [])],
            // Total score bounds, inclusive (ADR-018 addendum 2).
            'filter.score_min' => ['sometimes', 'integer', 'min:0', 'max:2000'],
            'filter.score_max' => ['sometimes', 'integer', 'min:0', 'max:2000', ...($this->filled('filter.score_min') ? ['gte:filter.score_min'] : [])],
            'filter.factory' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function sortColumnAndDirection(): array
    {
        return self::SORTS[$this->string('sort')->toString()] ?? self::SORTS['newest'];
    }

    public function fromTime(): ?Carbon
    {
        return $this->filled('filter.from') ? Carbon::createFromFormat('Y-m-d', (string) $this->input('filter.from'), 'UTC')?->startOfDay() : null;
    }

    public function toTime(): ?Carbon
    {
        return $this->filled('filter.to') ? Carbon::createFromFormat('Y-m-d', (string) $this->input('filter.to'), 'UTC')?->endOfDay() : null;
    }
}
