<?php

namespace App\Http\Requests\Api\V1\Concerns;

/**
 * Rules for the ordinary provider profile fields: the services workbook fields
 * (ADR-014) and the description and address added with self-registration (ADR-019).
 * The workbook marks no field as required, so all are optional; the formats are
 * technical checks only. Approval fields are deliberately absent, so they can never be
 * set through a profile request. The legal fields have their own rules
 * (legalDetailRules()), because who may change them depends on the approval state.
 */
trait ValidatesProviderProfile
{
    use ValidatesCodeLists;
    use ValidatesOrganizationDetails;

    /**
     * @return array<string, list<mixed>>
     */
    protected function providerProfileRules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'representative_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9+() -]{6,30}$/'],
            'website' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:255'],
            'dx_experience_years' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            ...$this->addressRules(),
            ...$this->codeListRules('sectors', 'sectors'),
            ...$this->codeListRules('services', 'catalog_services'),
        ];
    }
}
