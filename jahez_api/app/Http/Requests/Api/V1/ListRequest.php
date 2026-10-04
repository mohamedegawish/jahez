<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pagination input shared by list endpoints. Used as is, authorization is done by the
 * controller; subclasses that authorize in authorize() also run it before validation.
 */
class ListRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', self::DEFAULT_PER_PAGE);
    }
}
