<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Enums\DocumentType;

/**
 * Rules for the registration details factories and providers share (ADR-019): the legal
 * name, the address, the registration numbers and the uploaded documents. Every field is
 * optional, because the sources make none of them mandatory (OQ-18, OQ-19, OQ-36); the
 * formats are technical checks only.
 */
trait ValidatesOrganizationDetails
{
    /**
     * Commercial and tax registration numbers: digits (Latin or Arabic-Indic), letters,
     * spaces, slashes and hyphens. Their official formats are not documented, so nothing
     * stricter is checked.
     */
    public const REGISTRATION_NUMBER_PATTERN = '/^[0-9A-Za-z\x{0660}-\x{0669} \/-]{1,50}$/u';

    /**
     * @return array<string, list<mixed>>
     */
    protected function legalDetailRules(): array
    {
        return [
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'commercial_registration_number' => ['sometimes', 'nullable', 'string', 'regex:'.self::REGISTRATION_NUMBER_PATTERN],
            'tax_registration_number' => ['sometimes', 'nullable', 'string', 'regex:'.self::REGISTRATION_NUMBER_PATTERN],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function addressRules(): array
    {
        return [
            'governorate' => ['sometimes', 'nullable', 'string', 'max:100'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Optional uploads, each checked against its type's allowed content and size.
     *
     * @return array<string, list<mixed>>
     */
    protected function documentRules(): array
    {
        $rules = [];
        foreach (DocumentType::REQUEST_FIELDS as $field => $type) {
            $rules[$field] = ['sometimes', 'nullable', ...$type->fileRules()];
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'commercial_registration_number.regex' => 'The commercial registration number may contain only digits, letters, spaces, / and -, up to 50 characters.',
            'tax_registration_number.regex' => 'The tax registration number may contain only digits, letters, spaces, / and -, up to 50 characters.',
        ];
    }
}
