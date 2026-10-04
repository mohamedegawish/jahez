<?php

namespace App\Http\Requests\Api\V1\Concerns;

/**
 * Rules for the factory registration details (ADR-019): legal name, contact person,
 * website, address and registration numbers. All optional: the sources make none of
 * them mandatory (OQ-19).
 */
trait ValidatesFactoryProfile
{
    use ValidatesOrganizationDetails;

    /**
     * @return array<string, list<mixed>>
     */
    protected function factoryProfileRules(): array
    {
        return [
            ...$this->legalDetailRules(),
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9+() -]{6,30}$/'],
            'website' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:255'],
            ...$this->addressRules(),
        ];
    }
}
