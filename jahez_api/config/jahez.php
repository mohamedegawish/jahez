<?php

/*
|--------------------------------------------------------------------------
| Jahez business policies
|--------------------------------------------------------------------------
|
| Business rules the owner has not decided yet are isolated here, each with the
| open question that decides it (docs/open-questions.md). The defaults keep the
| documented interim behaviour: nothing is computed or charged that the owner has
| not approved. Change a value only with the owner's decision recorded in the
| open question or an ADR.
|
*/

return [

    'factories' => [
        /*
         * Company sizes a factory may declare: DOC §1 p.2 lists small, medium and
         * large industrial companies; p.1 names only small and medium (OQ-04). The
         * size is stored as declared, never derived.
         */
        'sizes' => [
            'small' => 'الصغيرة',
            'medium' => 'المتوسطة',
            'large' => 'الكبيرة',
        ],

        /*
         * Profile fields a factory should fill before it starts the readiness
         * assessment (OQ-19). They are shown as the onboarding checklist and never
         * block an assessment. Default: the sectors, without which no provider is
         * eligible for the factory (ADR-014). Allowed values: legal_name,
         * contact_name, contact_email, contact_phone, governorate, city, address,
         * commercial_registration_number, tax_registration_number, sectors.
         */
        'required_profile_fields' => array_values(array_filter(explode(',', (string) env('JAHEZ_FACTORY_REQUIRED_FIELDS', 'sectors')))),

        /*
         * Whether a factory's recorded legal information (legal name, registration
         * numbers and documents) changes only through a change request IMC reviews once
         * it holds a value (ADR-020, PROPOSED). Factories have no approval step
         * (OQ-18), so a value counts as recorded once it is set; an empty field is filled
         * directly.
         */
        'legal_changes_reviewed' => (bool) env('JAHEZ_FACTORY_LEGAL_CHANGES_REVIEWED', true),

        /*
         * Whether only a factory IMC approved may send service requests (ADR-021,
         * PROPOSED OQ-46). Self-registered factories start pending; factories created
         * by IMC, and those that existed before the review step, are approved. The
         * profile, documents and readiness assessment stay open whatever the status.
         */
        'approval_required' => (bool) env('JAHEZ_FACTORY_APPROVAL_REQUIRED', true),
    ],

    /*
     * Organization documents (ADR-019): logos and registration documents. The files
     * are stored on this private disk and served only through authorized API
     * endpoints. The type and size limits are technical defaults (PROPOSED), not
     * owner decisions; which documents are required is OQ-18.
     */
    'documents' => [
        'disk' => env('JAHEZ_DOCUMENTS_DISK', 'local'),
        'logo_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
        'logo_max_kb' => (int) env('JAHEZ_LOGO_MAX_KB', 2048),
        'legal_mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
        'legal_max_kb' => (int) env('JAHEZ_LEGAL_DOCUMENT_MAX_KB', 5120),
    ],

    /*
     * Public self-registration (ADR-019): requests per hour from one IP address.
     * A provisional abuse limit, like the login limits of ADR-011 (OQ-33).
     */
    'registration' => [
        'per_hour_per_ip' => (int) env('JAHEZ_REGISTRATIONS_PER_HOUR', 10),
    ],

    'providers' => [
        /*
         * Profile fields that must be filled before IMC approves a provider or the
         * provider asks for a new review (OQ-36). Empty: only the company name is
         * required, as the workbook marks nothing as required. Allowed values:
         * representative_name, job_title, email, phone, website,
         * dx_experience_years, sectors, services.
         */
        'required_profile_fields' => array_values(array_filter(explode(',', (string) env('JAHEZ_PROVIDER_REQUIRED_FIELDS', '')))),

        /*
         * Whether the provider directory shows the contact person, job title, email
         * and phone to factories (OQ-37). Hidden until the owner decides.
         */
        'directory_shows_contact_details' => (bool) env('JAHEZ_DIRECTORY_SHOWS_CONTACT_DETAILS', false),

        'evaluation' => [
            /*
             * Highest score per criterion of the DOC §6 evaluation (OQ-13). Null: the
             * scale is not approved, so evaluations record a written assessment per
             * criterion and no score.
             */
            'scale_max' => env('JAHEZ_PROVIDER_EVALUATION_SCALE_MAX') !== null ? (int) env('JAHEZ_PROVIDER_EVALUATION_SCALE_MAX') : null,

            /*
             * Minimum weighted total (0-100) shown as "meets the pass mark" (OQ-13).
             * Null: not approved, so no pass or fail is shown. Approval stays a manual
             * IMC decision either way (owner decision 2026-10-03).
             */
            'pass_mark' => env('JAHEZ_PROVIDER_EVALUATION_PASS_MARK'),
        ],
    ],

    'marketplace' => [
        /*
         * Whether accepting one provider's offer awards the whole request: the other
         * open threads close and no further offer can be accepted (OQ-38). True keeps
         * the PROPOSED single-award workflow; false lets a factory agree with several
         * providers on one request.
         */
        'single_award' => (bool) env('JAHEZ_MARKETPLACE_SINGLE_AWARD', true),
    ],

    'agreements' => [
        /*
         * Whether an agreement needs IMC's approval before a contract draft or an invoice
         * can be made for it (ADR-020). The owner's Phase 2 brief requires the ministry
         * approval, so it is on by default. What follows a rejection for the request and
         * the parties is not decided (OQ-43).
         */
        'imc_approval_required' => (bool) env('JAHEZ_AGREEMENTS_IMC_APPROVAL_REQUIRED', true),
    ],

    'notifications' => [
        /*
         * Whether platform events are also emailed to recipients who keep email
         * notifications on (ADR-020). In-app notifications are always stored. Emails go
         * through the configured mailer on the queue, and never carry message text,
         * offer terms, prices or documents.
         */
        'mail' => (bool) env('JAHEZ_NOTIFICATION_EMAILS', true),
    ],

    /*
    |----------------------------------------------------------------------
    | Financial and contract policies (ADR-023)
    |----------------------------------------------------------------------
    |
    | Who issues invoices, their numbering, taxes and fees, payment terms, the
    | IMC revenue share and contract templates are business rules: IMC manages
    | them as approved, versioned policies in the database, never here. Only the
    | calendar their effective dates are read in is a setting.
    |
    */
    'financial_policies' => [
        'timezone' => env('JAHEZ_BUSINESS_TIMEZONE', 'Africa/Cairo'),

        /*
         * The former environment settings for these rules (ADR-017), read only so that
         * app:check-production can report any that are still set: they have no effect.
         */
        'ignored_environment_settings' => array_keys(array_filter([
            'JAHEZ_INVOICE_ISSUER' => env('JAHEZ_INVOICE_ISSUER'),
            'JAHEZ_INVOICE_NUMBER_PREFIX' => env('JAHEZ_INVOICE_NUMBER_PREFIX'),
            'JAHEZ_TAX_RATE_PERCENT' => env('JAHEZ_TAX_RATE_PERCENT'),
            'JAHEZ_REVENUE_SHARE_PERCENT' => env('JAHEZ_REVENUE_SHARE_PERCENT'),
        ], fn (mixed $value): bool => $value !== null && $value !== '')),
    ],

    /*
    |----------------------------------------------------------------------
    | Payments (ADR-017)
    |----------------------------------------------------------------------
    |
    | The payment gateway is a technical integration with credentials, so it is
    | a setting. No gateway is chosen (OQ-16): while none is configured,
    | starting a payment is refused with 409 `policy_not_configured`.
    |
    */
    'billing' => [
        /*
         * The payment gateway adapter to use, by key (OQ-16). Unset: payments cannot be
         * started. No gateway adapter ships with the application yet; one is registered
         * in `gateways` (key => class implementing App\Billing\PaymentGateway).
         */
        'payment_gateway' => env('JAHEZ_PAYMENT_GATEWAY'),

        'gateways' => [],
    ],

];
