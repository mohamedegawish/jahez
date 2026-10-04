<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesProviderProfile;
use App\Models\ServiceProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreServiceProviderRequest extends FormRequest
{
    use ValidatesProviderProfile;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('create', ServiceProvider::class);
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
            ...$this->providerProfileRules(),
            ...$this->legalDetailRules(),
        ];
    }
}
