# Phase 4: Service Catalog, Provider Profiles, Approval and Directory

**Date:** 2026-10-03 · **Branch:** `phase/04-07-marketplace` (from `phase/10-tooling-and-api-only` @ `e921f24`) · **Result:** complete for the owner-approved scope; gate **PASS** (§7)

Phases 4–7 came as one combined brief ("Jahez — Combined AI Coding Prompt for Phases 4–7") and were built together, with a separate gate for each phase. The suite, static analysis, mutation, Postman and security results for all four phases are in the [Phase 6 log](phase-06-requests-offers-negotiation.md#4-tests-and-checks); this log keeps what is specific to Phase 4.

## 1. Scope

**Delivered:**
- **Service catalog:** 7 categories and 42 services from the services workbook.
  - `GET /catalog/categories` and `/catalog/categories/{id}`.
  - `GET /catalog/services` and `/catalog/services/{id}`, with `filter[category]`, plus `filter[eligible]` for factory members.
- **Provider profile:**
  - the workbook's fields: company name, representative, job title, email, phone, website, years of digital-transformation experience, target sectors;
  - plus the services the provider offers;
  - a provider's own members edit their own profile.
- **IMC approval:** `POST /service-providers/{id}/approval`.
  - States `pending`, `approved`, `rejected`, `suspended`; a reason is required to reject or suspend.
  - The row is locked, a transition the status does not allow gets 409, and every decision is audited.
- **Provider directory:** `GET /provider-directory`.
  - A factory member sees the providers eligible for their factory; IMC sees every approved provider.
  - Allow-listed filters and sort, escaped search, paginated.
  - No contact details.

**Not delivered (blocked, not guessed):**
- The weighted provider evaluation (DOC §6 weights) and per-level eligibility: the scale and pass mark ([OQ-13](../open-questions.md#oq-13)) and the per-level requirements ([OQ-14](../open-questions.md#oq-14)) are not approved. Approval is a manual IMC decision with no score.
- Required provider fields ([OQ-36](../open-questions.md#oq-36)): only the company name is required.

## 2. Source correction: the workbook was in the repository

The services workbook (`docs/Copy of الخدمات التحول الرقمي.xlsx`) has been in the repository since the baseline commit `020f811`. It was copied in 23 minutes before that commit, after the Phase 0 search, and **Phases 1–3 kept reporting [OQ-01](../open-questions.md#oq-01) as "not supplied" without re-checking**. It was found while planning Phases 4–7. The correction is recorded in OQ-01, the [traceability matrix](../requirements-traceability.md) and [ADR-014](../decisions/ADR-014-catalog-and-provider-profiles.md). The agent guidelines now require checking `docs/` for supplied sources at the start of each phase.

Read in full: one sheet, A1:D61, 63 shared strings. It is a **provider registration form**:
- 8 provider fields, none marked required;
- 7 categories, with Arabic names and English in parentheses;
- 43 service rows. Rows 59–60 are one service (owner decision), giving 42.

It defines no factory fields, so [OQ-19](../open-questions.md#oq-19) stays open. The `.docx` version of the source document is not in the project; the document was read from its PDF in Phase 0.

## 3. Decisions

**Owner (2026-10-03, OWNER-APPROVED), applied here:**
1. Providers appear to factories only **after manual IMC approval**. A reason is required to reject or suspend, and there is no score until OQ-13.
2. **Eligibility:** the provider is approved and targets at least one of the factory's sectors. A request also needs it to offer the chosen service (Phase 6).
3. Workbook **rows 59–60 are one service:** «إدارة التدريب والثقافة الرقمية.»
4. **Provider members edit their own** workbook fields and services, never the approval.
5. **An approved provider stays approved** after editing. Edits are audited, and IMC can suspend.

**Technical (ADR-014):**
- **Codes:** stable `<category>.<nn>` codes in workbook order, for example `automation_ot.03`.
- **Text:** the service text is the cell text trimmed, nothing else changed. `name_en` holds only the English the workbook prints.
- **Duplicates:** «إعادة هندسة ورقمنة العمليات.» appears in categories 1 and 7 and is kept as two services.
- **Validation:** the Phase 3 sector-list validation became the generic `ValidatesCodeLists`, used for both sectors and services. Its FC-08 bound (at most as many entries as exist) now covers services too.
- **Directory contents:** name, website, experience, sectors and services. Contact person, title, email and phone are hidden (PROPOSED, [OQ-37](../open-questions.md#oq-37)).
- **Sector filter:** a factory member may filter only by one of their own sectors (422 otherwise).

## 4. Data model, endpoints and permissions

- **Migrations:**
  - `create_service_categories_table`;
  - `create_catalog_services_table`;
  - `add_profile_and_approval_to_service_providers_table`: 6 profile columns, plus `approval_status` (default `pending`, indexed), `approval_reason` and `approval_changed_at`;
  - `create_catalog_service_service_provider_table`.

  See [data-model.md](../data-model.md).
- **Seeder:** `ServiceCatalogSeeder`, inside `ReferenceDataSeeder`. It is idempotent by code and stores each row's workbook cell in `source_ref`. `LocalDemoSeeder` (local and testing only) approves the demo providers and assigns them services.
- **Permission:** `service_providers.approve`. The decision matrix is in [roles-permissions.md](../roles-permissions.md).
- **Audit:** the new `service_provider.approval_changed` event records `from`, `to` and the reason. Provider profile updates now record:
  - the name from and to;
  - the names of the changed fields only;
  - the sector and service lists from and to.
- **Endpoints:** [api-endpoints.md](../api-endpoints.md) (Service providers, Catalog, Provider directory).

## 5. Tests

| File | Tests | What it proves |
| --- | --- | --- |
| `Database/ServiceCatalogSourceTest` | 3 | Reads the `.xlsx` itself (ZipArchive and SimpleXML) and compares every category and service with the seeded rows; rows 59–60 joined; seeding twice creates no duplicates |
| `Api/V1/CatalogTest` | 12 | Categories in workbook order; services by category; `filter[eligible]` for factory members only (422 for others); unknown filters → 422; 404; 401 |
| `Api/V1/ProviderApprovalTest` | 20 | Every allowed transition; every refused one → 409 with nothing changed; reasons required to reject or suspend; unknown decisions → 422; own member 403, others 404; 401 |
| `Api/V1/ProviderDirectoryTest` | 25 | Only approved providers in the factory's sectors; a factory with no sectors sees none; filters and sort, unknown ones → 422; `%` and `_` searched literally; no contact or approval fields; IMC sees every approved provider; provider members 403; constant query count (no N+1) |
| `Api/V1/ServiceProviderTest` | 26 (Phase 3 file, extended) | Workbook fields and boundaries (email, URL, phone, years); services list; own-member edits that leave the approval untouched; audit metadata |
| `Policies/ServiceProviderPolicyTest` | 18 (extended) | `update` and `approve` for every actor type |
| `Audit/AuditTrailTest` | 30 in total (shared) | Approval decision recorded with from, to and reason |

Mutation checks on the Phase 4 controls: 11, all caught. The list is in the [Phase 6 log](phase-06-requests-offers-negotiation.md#5-verification).

**Failures during the phase (not hidden):**
- The "unknown service" validation case reported its error under `services.0`, not `services`, so a dataset column was added for the error key.
- The N+1 test first counted 7 queries instead of 6, because the test user's factory relation was cached. It now calls `unsetRelation()`.
- Larastan reported a nullable captured `$factory` and a nullable category in `CatalogServiceResource`. Both were fixed in the code, not suppressed.

## 6. Docs updated

- [ADR-014](../decisions/ADR-014-catalog-and-provider-profiles.md) (new).
- [open-questions.md](../open-questions.md): OQ-01 and OQ-20 answered; OQ-36 and OQ-37 added.
- [requirements-traceability.md](../requirements-traceability.md) §5 Service catalog, verified against the workbook.
- [data-model.md](../data-model.md), [roles-permissions.md](../roles-permissions.md), [api-endpoints.md](../api-endpoints.md), [api-conventions.md](../api-conventions.md) (`filter[]` and `sort` in force; reference data not paginated), [workflows.md](../workflows.md) §1.
- The Postman folders 04, 05 and 06.

## 7. Exit gate

| Criterion | Evidence | Result |
| --- | --- | --- |
| Catalog exactly as the workbook lists it, nothing invented | `ServiceCatalogSourceTest` compares every row with the `.xlsx` file | PASS |
| Provider fields from the workbook only; validation technical only | ADR-014; `ServiceProviderTest` boundaries | PASS |
| Approval before visibility; reason to reject or suspend; no score | `ProviderApprovalTest`, `ProviderDirectoryTest`; no score column | PASS |
| Tenant isolation (other provider 404; own member cannot approve) | Policy matrix and HTTP tests; mutation-verified | PASS |
| Filters and sort allow-listed; search escaped; no N+1 | `ProviderDirectoryTest` (SQL wildcards, query count); mutation-verified | PASS |
| Audited inside the transaction | `AuditTrailTest` | PASS |
| Suite, Larastan, Pint, Postman on the combined branch | [Phase 6 log §4](phase-06-requests-offers-negotiation.md#4-tests-and-checks) | PASS |

**Gate: PASS** for the approved scope. The weighted evaluation stays blocked on OQ-13 and OQ-14. The directory's hidden contact details (OQ-37) and the required provider fields (OQ-36) await owner confirmation.
