<?php

namespace App\Http\Requests\Api\V1\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Rules for an optional list of reference-data codes (sector codes, catalog service
 * codes), shared by the organization requests.
 */
trait ValidatesCodeLists
{
    /**
     * A valid list names each code at most once, so it can never be longer than the
     * reference table. A longer list is rejected without checking its elements, because
     * the per-element `distinct` check takes time that grows with the square of the
     * list's length (finding FC-08). Requests without the field need no rules, and no
     * count query.
     *
     * @return array<string, list<mixed>>
     */
    protected function codeListRules(string $field, string $table): array
    {
        if (! $this->has($field)) {
            return [];
        }

        $codeCount = DB::table($table)->count();
        $rules = [$field => ['sometimes', 'array', 'max:'.$codeCount]];

        if (count((array) $this->input($field, [])) <= $codeCount) {
            $rules[$field.'.*'] = ['string', 'distinct', Rule::exists($table, 'code')];
        }

        return $rules;
    }
}
