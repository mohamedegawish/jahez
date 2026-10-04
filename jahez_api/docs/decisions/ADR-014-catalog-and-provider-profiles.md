# ADR-014: Service catalog, provider profiles, approval and eligibility

- **Status:** Accepted (2026-10-03, Phase 4).
- **Decided by:** Project owner. The marketplace model came with the combined Phase 4–7 brief; approval before visibility, sector eligibility, the reading of workbook rows 59–60, and self-edits were answered during planning on 2026-10-03. The schema choices below are technical.

## Source

The services workbook `docs/Copy of الخدمات التحول الرقمي.xlsx` (one sheet, A1:D61) is a **provider registration form**:
- **Provider data** (rows 1–9): 8 fields, none marked as required.
- **The service structure** "هيكلية خدمات التحول الرقمي والتصنيع الذكي (Industry 4.0)" (rows 11–61): 7 categories, each with its services, and tick boxes for the ones a provider offers.

It has been in the repository since the baseline commit; see the correction in [OQ-01](../open-questions.md#oq-01).

## Catalog

- **Tables:**
  - `service_categories`: 7 rows; `name_ar` is the Arabic without the list number, and `name_en` is the English the workbook prints in parentheses.
  - `catalog_services`: 42 services, with the cell text trimmed and nothing else changed.
- **Traceability:** every row stores its workbook cell (`source_ref`).
- **Codes** are stable technical identifiers: the category, then the position, for example `automation_ot.03`.
- **Rows 59–60 are one service** («إدارة التدريب والثقافة الرقمية.»): row 60 begins with «و» and continues row 59 (owner decision).
- **«إعادة هندسة ورقمنة العمليات.» appears in categories 1 and 7.** Both are kept, as two services.
- **Seeding:** `ServiceCatalogSeeder`, part of `ReferenceDataSeeder`, idempotent by code. `ServiceCatalogSourceTest` reads the `.xlsx` file itself and compares every row.

## Provider profile

- **Columns:** the workbook fields `name` (company), `representative_name`, `job_title`, `email`, `phone`, `website`, `dx_experience_years`, plus target sectors (the existing pivot) and offered services (`catalog_service_service_provider`).
- **All optional except the company name.** The workbook marks nothing as required; required fields wait for [OQ-36](../open-questions.md#oq-36).
- **Validation is technical only:** email format, an http(s) URL, phone characters, years from 0 to 100, and bounded code lists.
- **Who edits:** IMC administrators edit any provider. A provider's own members edit their own workbook fields and services (owner decision).
- **Audit:** profile edits record the name and the sector and service lists before and after, plus only the names of changed contact fields.

## Approval and visibility

- **States:** `approval_status` is `pending`, then `approved` or `rejected`; `approved` can become `suspended`; `rejected` and `suspended` can return to `approved`.
- **Manual decision:** an IMC administrator decides (permission `service_providers.approve`), and rejecting or suspending needs a reason. No evaluation score is computed until the scale and pass mark are approved ([OQ-13](../open-questions.md#oq-13)).
- **Concurrency:** the provider row is locked, and a transition the current status does not allow gets 409.
- **Visibility:** only `approved` providers are visible to factories. Editing the profile keeps an approved provider approved (owner decision).

## Eligibility and discovery

- **Eligible for a factory** (owner decision): **approved, and targeting at least one of the factory's sectors.** A request additionally needs the provider to **offer the chosen service**.
- **`GET /provider-directory`:**
  - a factory member sees the providers eligible for their factory; IMC sees every approved provider; provider members get 403;
  - allow-listed `filter[service|category|sector]` (a factory member may filter only by their own sectors), `search` (LIKE wildcards escaped), and `sort` (`name`, `dx_experience_years`, either direction);
  - paginated.
- **What the directory shows:** company name, website, experience, sectors and services. Contact person, title, email and phone are hidden (PROPOSED, [OQ-37](../open-questions.md#oq-37)).

## Consequences

- No provider is visible until IMC approves it. The local demo seeder approves the demo providers.
- Any later change to the workbook means editing `ServiceCatalogSeeder`. The source test then shows exactly which rows differ.
