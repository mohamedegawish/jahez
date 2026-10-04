# Onboarding Phase 1: identity, registration, profiles and assessment foundation

- **Date:** 2026-10-03. **Brief:** owner's "Phase 1 — Jahez Identity, Onboarding, Profiles & Assessment Foundation".
- **Decisions:** [ADR-019](../decisions/ADR-019-self-registration-documents-and-legal-changes.md) (self-registration, documents, reviewed legal changes; revises ADR-011), [ADR-018 addendum](../decisions/ADR-018-digital-readiness-assessment.md#addendum-2026-10-03-questionnaire-administration-and-duplicate-submissions) (questionnaire versions, idempotent submission). Approval row A16 in the implementation plan.
- **State:** uncommitted, on top of the uncommitted readiness-assessment work (branch `phase/04-07-marketplace`). Nothing was committed or pushed.

## 1. Scope delivered

- **API:** public registration for factories and providers (queued, enumeration-safe), new profile fields, private organization documents with authorized download, provider legal change requests with IMC review, factory onboarding state, questionnaire version administration (draft → publish), `Idempotency-Key` on assessment submission, seeder no longer resets the current version.
- **Web client (`front/`):** public layout without the portal sidebar for `/` and `/ads/:id`; portals render the sidebar only behind `RequireAuth`; real registration forms (multipart); provider and factory profiles with logo/documents and the legal change-request panel; onboarding steps on the factory dashboard and profile; idempotent assessment submission; IMC change-request queue and document review in Provider Approvals; new IMC page "استبيان الجاهزية الرقمية" for questionnaire versions; provider logos in the directory.

## 2. Assessment source check

The seeded questionnaire was compared with `docs/إطار تقييم مستوى الجاهزية الرقمية.docx` (text extracted from the .docx): 5 pillars, 10 questions, 4 choices each scored أ1 ب2 ج3 د4, totals 10–40, categories 10–17 / 18–25 / 26–33 / 34–40. The existing `ReadinessAssessmentSourceTest` reads the .docx itself and passes.

## 3. Database changes (all additive, nullable or defaulted)

| Migration | Change |
| --- | --- |
| `2026_10_03_200001` | `service_providers`: legal_name, description, governorate, city, address, commercial_registration_number, tax_registration_number |
| `2026_10_03_200002` | `factories`: legal_name, contact_name/job_title/email/phone, website, governorate, city, address, registration numbers |
| `2026_10_03_200003` | `provider_profile_change_requests` (one open request per provider via unique `is_open`) |
| `2026_10_03_200004` | `organization_documents` |
| `2026_10_03_200005` | `readiness_questionnaires.published_at` (backfilled for current/answered versions); `readiness_assessments.idempotency_key` + unique (factory, key) |

## 4. Commands and real results

| Command | Result |
| --- | --- |
| `php artisan test --compact` (baseline, before changes) | 904 passed (2856 assertions), 334.8 s |
| `php artisan migrate` (development DB) | 5 migrations DONE |
| `php artisan db:seed --class=ReferenceDataSeeder` ×2 (development DB) | Same counts after both runs: 1 version, 5 pillars, 10 questions, 40 choices, 4 categories (10–17, 18–25, 26–33, 34–40), 48 recommendations, 45 mappings |
| `php artisan test --compact` (final) | **989 passed (3320 assertions)**, 356 s. One intended contract change updated: `ProviderDirectoryTest` field list now includes description, governorate, city, has_logo, and asserts legal numbers stay hidden |
| `composer analyse` | No errors |
| `vendor/bin/pint --dirty --format agent` | passed |
| `front: npx tsc -b` / `npm run lint` / `npm run build` | no errors / no warnings / built (existing >500 kB chunk warning) |
| End-to-end script against `php artisan serve` + development DB (registration → queue → mailed link → password → login → profile/uploads → assessment → approval → directory → change request) | 34/34 checks passed |
| Headless Chrome against the Vite dev server | landing: no `<aside>` (sidebar); no horizontal overflow at 375–390 px on `/`, `/register/*`, `/login`; UI registration submitted and persisted; portal pages render the sidebar; factory member redirected from `/admin/*` to `/forbidden` |

New tests: `RegistrationTest` (18), `OrganizationDocumentTest` (13), `ProviderChangeRequestTest` (11), `ReadinessQuestionnaireVersionTest` (18), additions to `ReadinessAssessmentTest` (idempotency, nonexistent choice id, non-id answers), `FactoryTest` (profile/onboarding), `AuditTrailTest`, policy matrices, `CheckProductionConfigurationTest`.

Note: several older `php` processes were already listening on port 8000 on the development machine and answered with stale routes; the end-to-end run used port 8010 instead, and the UI check used Vite on 3010 with `CORS_ALLOWED_ORIGINS` overridden for that process only.

## 5. Open issues

- Required documents and factory approval: OQ-18. Required factory fields: OQ-19. Required provider fields: OQ-36.
- Roadmap recommendations of a draft questionnaire are copied, not editable.
- The development DB now contains the test registrations made by the end-to-end runs (`e2e-*`, `ui-factory-*` @example.test).
- `docs/phases/phase-05b-readiness-assessment.md` is linked from the plan but does not exist in the working tree.
