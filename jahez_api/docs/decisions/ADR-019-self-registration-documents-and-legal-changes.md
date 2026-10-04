# ADR-019: Self-registration, organization documents and reviewed legal changes

- **Status:** Accepted (2026-10-03). **Revises [ADR-011](ADR-011-account-provisioning-and-credentials.md)** on one point: accounts are no longer created only by IMC administrators. Everything else in ADR-011 (invitation links, password policy, abuse controls, no account enumeration) still applies.
- **Decided by:** Project owner, in the "Phase 1 — Identity, Onboarding, Profiles & Assessment Foundation" brief (2026-10-03): factories and providers register from the public landing page; providers submit logo, description, address, tax and commercial registration details and documents; factories submit their information before the readiness assessment; verified legal information is changed only through review; an unapproved provider never becomes eligible. The technical choices below were made during implementation.

## Decision

1. **Public registration, completed in a queue job.**
   - `POST /registration/factories` and `POST /registration/service-providers` (multipart) validate the form, store the uploads on the private documents disk and dispatch `RegisterOrganization`. The response is always the same 202 message.
   - The job creates the organization, its first member (random unknown password) and the documents in one transaction with the audit entry (`factory.registered` / `service_provider.registered`), then queues the existing `SendAccountInvitation`. Setting the password through the emailed link proves ownership of the address, as for invited accounts.
   - **Email already registered** (same address apart from letter case): nothing is created, the uploads are deleted, and the account owner receives `RegistrationForExistingAccount`. The response and the request's work are identical either way, so neither reveals which emails exist (ADR-011).
   - **Abuse limit:** `throttle:registration`, `JAHEZ_REGISTRATIONS_PER_HOUR` per IP (default 10, provisional like OQ-33).
   - **Public options:** `GET /registration/options` returns the sectors, factory sizes, catalog and upload limits. The signed-in reference endpoints stay authenticated.
2. **Approval unchanged.** A registered provider starts `pending` and reaches factories only after IMC approval (ADR-014). Approval fields are never accepted from a form. Factories have no approval step: none is documented (OQ-18 records the question).
3. **Profile fields.** All new fields are nullable; none is mandatory beyond what was already required (company name; for a registration also the contact person's name and email, and for a factory at least one sector, without which no provider is eligible for it).
   - Providers: `legal_name`, `description`, `governorate`, `city`, `address`, `commercial_registration_number`, `tax_registration_number`.
   - Factories: `legal_name`, `contact_name`, `contact_job_title`, `contact_email`, `contact_phone`, `website`, `governorate`, `city`, `address`, the two registration numbers.
   - Registration numbers are checked only for characters and length (their official formats are not documented).
   - Factory onboarding: `FactoryResource.onboarding` lists the configured fields still missing (`JAHEZ_FACTORY_REQUIRED_FIELDS`, default `sectors`) and whether the assessment is done. It is guidance only; it never blocks an assessment.
4. **Documents.** `organization_documents` (one of `factory_id` / `service_provider_id`), types `logo`, `commercial_registration`, `tax_registration`.
   - Stored on `JAHEZ_DOCUMENTS_DISK` (default `local`, private, not served) under random names; the client file name is metadata only.
   - Content type is checked from the bytes (`mimes`): logo jpg/png/webp ≤ 2 MB; legal documents pdf/jpg/png ≤ 5 MB. SVG is refused. Limits are technical defaults (PROPOSED).
   - Files are read only through authorized endpoints with the bearer token: the organization's members and IMC administrators; a provider's logo also through `GET /provider-directory/{id}/logo` for those who may open its directory profile. Legal documents are never shown to factories.
   - A replaced file is kept as `superseded`; nothing is deleted. Editing other profile fields never touches documents.
5. **Verified legal information.** Once a provider has been approved (status `approved` or `suspended`), its members cannot change `legal_name`, the registration numbers or the registration documents directly (422 on the field; 409 on a legal document upload). They submit a change request:
   - `provider_profile_change_requests`: requested values (only those that differ), documents attached as `pending_review`, one pending request per provider (unique key on `is_open`).
   - IMC administrators with `service_providers.approve` approve (values applied, documents become active, the previous ones superseded) or reject with a reason; the member may cancel. The provider stays approved throughout.
   - Before the first approval the whole profile is under review anyway, so members edit the legal fields directly. IMC administrators always edit directly.
   - Audit: submitted, approved, rejected, cancelled; field names and document types only, never the values.
6. **Locks.** Change-request actions lock the provider row, then the request row. Document uploads lock the organization row.

## Consequences

- OQ-18 is answered for the registration model (self-registration, IMC approval for providers). Which documents are **required** and whether factories need approval stay open.
- OQ-19 is partly answered: the owner named the factory fields; which of them are required is configurable and still open.
- New production settings are checked by `app:check-production`: the documents disk must exist and be neither public nor served; the factory onboarding fields must be known names.
- Uploads need `upload_max_filesize` / `post_max_size` of at least 16 MB on the API host (three files of up to 5 MB in one registration).
- Registrations depend on a running queue worker, like invitations (RK-17).
