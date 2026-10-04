<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesCodeLists;
use App\Http\Requests\Api\V1\Concerns\ValidatesOrganizationDetails;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Public factory registration (ADR-019), sent as multipart form data. The contact person
 * becomes the factory's first member and receives the set-password email. Whether the
 * email already has an account is decided in the queued job, never here, so the response
 * cannot reveal it (ADR-011).
 */
class RegisterFactoryRequest extends FormRequest
{
    use ValidatesCodeLists;
    use ValidatesOrganizationDetails;

    /**
     * Get the validation rules that apply to the request. A factory needs at least one
     * sector: without one no provider is eligible for it (ADR-014).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            ...$this->legalDetailRules(),
            'size' => ['sometimes', 'nullable', 'string', Rule::in(array_keys((array) config('jahez.factories.sizes')))],
            ...$this->codeListRules('sectors', 'sectors'),
            'sectors' => ['required', 'array', 'min:1', 'max:'.DB::table('sectors')->count()],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9+() -]{6,30}$/'],
            'website' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:255'],
            ...$this->addressRules(),
            ...$this->documentRules(),
        ];
    }
}
