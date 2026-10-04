<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesProviderProfile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Public provider registration (ADR-019), sent as multipart form data: the workbook
 * fields (ADR-014) plus the registration details the owner added. The representative
 * becomes the provider's first member and receives the set-password email. The provider
 * starts pending: factories see it only after IMC approves it. Whether the email already
 * has an account is decided in the queued job, never here (ADR-011).
 */
class RegisterServiceProviderRequest extends FormRequest
{
    use ValidatesProviderProfile;

    /**
     * Get the validation rules that apply to the request. Only the company name and the
     * representative's name and email are required: the account needs them, and the
     * workbook marks nothing else as required (OQ-36).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->providerProfileRules(),
            'name' => ['required', 'string', 'max:255'],
            ...$this->legalDetailRules(),
            'representative_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            ...$this->documentRules(),
        ];
    }
}
