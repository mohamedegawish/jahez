<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesRecommendedFilter;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Filters for the provider directory (ADR-014). Every filter and sort key is
 * allow-listed; values are bound as query parameters. filter[recommended] (factory
 * members, ADR-018) keeps the eligible providers that offer a service the readiness
 * roadmap recommends for the factory; it never adds a provider that is not eligible.
 */
class ListProviderDirectoryRequest extends ListRequest
{
    use ValidatesRecommendedFilter;

    /**
     * Sort keys and the column and direction each one means.
     *
     * @var array<string, array{string, string}>
     */
    public const SORTS = [
        'name' => ['name', 'asc'],
        '-name' => ['name', 'desc'],
        'dx_experience_years' => ['dx_experience_years', 'asc'],
        '-dx_experience_years' => ['dx_experience_years', 'desc'],
    ];

    public function authorize(): bool
    {
        return Gate::allows('viewDirectory', ServiceProvider::class);
    }

    /**
     * A factory member may filter only by one of their factory's own sectors, because
     * providers outside those sectors are not eligible for them anyway.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();
        $factory = $user instanceof User ? $user->industrialFactory : null;

        return [
            ...parent::rules(),
            'filter' => ['sometimes', 'array:service,category,sector,recommended'],
            'filter.recommended' => ['sometimes', 'boolean'],
            'filter.service' => ['sometimes', 'string', Rule::exists('catalog_services', 'code')],
            'filter.category' => ['sometimes', 'string', Rule::exists('service_categories', 'code')],
            'filter.sector' => [
                'sometimes',
                'string',
                $factory !== null ? Rule::in($factory->sectors()->pluck('code')->all()) : Rule::exists('sectors', 'code'),
            ],
            'search' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', 'string', Rule::in(array_keys(self::SORTS))],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateRecommendedFilter($validator),
        ];
    }

    /**
     * @return array{string, string}
     */
    public function sortColumnAndDirection(): array
    {
        return self::SORTS[$this->string('sort', 'name')->toString()];
    }
}
