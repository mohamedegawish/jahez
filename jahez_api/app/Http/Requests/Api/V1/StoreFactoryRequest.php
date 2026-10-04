<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesCodeLists;
use App\Http\Requests\Api\V1\Concerns\ValidatesFactoryProfile;
use App\Models\Factory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreFactoryRequest extends FormRequest
{
    use ValidatesCodeLists;
    use ValidatesFactoryProfile;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('create', Factory::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'size' => ['sometimes', 'nullable', 'string', Rule::in(array_keys((array) config('jahez.factories.sizes')))],
            ...$this->codeListRules('sectors', 'sectors'),
            ...$this->factoryProfileRules(),
        ];
    }
}
