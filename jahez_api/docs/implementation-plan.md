# Implementation Plan: Jahez API

**Last updated:** 2026-10-03 (digital readiness assessment: [Phase 5b log](phases/phase-05b-readiness-assessment.md); before it, Phases 4–7 and the business-logic completion: [Phase 6 log §9](phases/phase-06-requests-offers-negotiation.md#9-business-logic-completion-2026-10-03))
**Governing brief:** `Jahez_Laravel_API_Master_Prompt.md` (sections 6–11 hold the full per-phase checklists. This plan adds the project-specific scope, dependencies and blockers.)
**Priority order:** business-rule correctness → security and tenant isolation → data integrity under concurrency → measured performance.

## 1. Status

| Phase | Title | Status | Gate | Blocked by |
| --- | --- | --- | --- | --- |
| 0 | Repository discovery & baseline | **Complete** | **PASS**, with blocker OQ-01 carried forward (see [phase log](phases/phase-00-discovery.md#8-exit-gate)) | — |
| 1 | Foundation & quality gates | **Complete** | **PASS** (see [phase log](phases/phase-01-foundation.md#7-exit-gate)) | — |
| 2 | Data model & migrations | **Complete for the approved scope** (DOC reference data). The remaining entities are blocked and are built in their own phases. | **PASS** (see [phase log](phases/phase-02-data-model.md#7-exit-gate)) | Catalog: [OQ-01](open-questions.md#oq-01). Organizations: [OQ-19](open-questions.md#oq-19)/[OQ-20](open-questions.md#oq-20). Engagements: [OQ-03](open-questions.md#oq-03). |
| 3 | Authentication, authorization, account lifecycle | **Complete** (decisions D1–D4 applied) | **PASS** (see [phase log](phases/phase-03-auth.md#8-exit-gate)) | Interim decisions pending owner confirmation: [OQ-18](open-questions.md#oq-18), [OQ-21](open-questions.md#oq-21), [OQ-22](open-questions.md#oq-22), [OQ-33](open-questions.md#oq-33) |
| 4 | Catalog, provider profiles, IMC approval, provider directory | **Complete for the approved scope**, plus reference-data endpoints, directory profiles, admin filters, the review queue and re-review request, and DOC §6 evaluations under the OQ-13 interim (scores once a scale is configured) | **PASS** (see [phase log](phases/phase-04-catalog-providers.md#7-exit-gate)) | Evaluation: [OQ-13](open-questions.md#oq-13), [OQ-14](open-questions.md#oq-14). To confirm: [OQ-36](open-questions.md#oq-36), [OQ-37](open-questions.md#oq-37) |
| 5 | Factory self-edit and **digital readiness assessment** | **Complete**: the owner-supplied questionnaire (10 questions, 5 pillars), server-side score (10–40) and category, history, recommendations and the recommended-provider filter ([ADR-018](decisions/ADR-018-digital-readiness-assessment.md)). Manual classification (ADR-016) retired; its records are kept read-only | **PASS** (see [Phase 5b log](phases/phase-05b-readiness-assessment.md#7-exit-gate); the manual slice: [phase log](phases/phase-05-factory-assessments.md#7-exit-gate)) | To confirm: [OQ-08](open-questions.md#oq-08) (expert validation), [OQ-41](open-questions.md#oq-41), [OQ-42](open-questions.md#oq-42); not built: [OQ-10](open-questions.md#oq-10)–[OQ-12](open-questions.md#oq-12), [OQ-19](open-questions.md#oq-19) |
| 6 | Service requests, provider answers, negotiation, offers | **Complete**, plus thread history, adding providers to an open request, offer validity dates and a configurable single-award rule; states PROPOSED | **PASS, with PROPOSED states** (see [phase log](phases/phase-06-requests-offers-negotiation.md#7-exit-gate)) | To confirm: [OQ-38](open-questions.md#oq-38), [OQ-39](open-questions.md#oq-39), [OQ-40](open-questions.md#oq-40). Contracts: **[OQ-17](open-questions.md#oq-17)** |
| 7 | Agreements, contracts, billing, payments, revenue rules | **Boundaries built** ([ADR-017](decisions/ADR-017-agreements-contracts-billing.md)): agreements, contract drafts (never binding), invoices, payments; every money operation answers 409 `policy_not_configured` until the owner sets its rule | **BLOCKED for money operations; boundaries PASS** (see [phase log](phases/phase-07-billing-payments.md)) | **[OQ-15](open-questions.md#oq-15)**, **[OQ-16](open-questions.md#oq-16)**, invoicing, VAT, gateway, refunds, payouts |
| 8 | Notifications, audit, reporting, admin | **Audit-log slice complete**; notifications and reports not started | Partial (see [phase log](phases/phase-08-audit-log.md#6-exit-gate-phase-8-partial)) | Retention [OQ-25](open-questions.md#oq-25); [OQ-26](open-questions.md#oq-26), [OQ-27](open-questions.md#oq-27) |
| 9 | Security hardening & abuse testing | **First pass complete** over Phases 1–3 and the audit slice. Phases 4–6 were reviewed with the `code-review` skill (CR-26 to CR-35) and mutation checks; a full `finecomb` pass over Phases 4–6 is still to do | **PASS for the current surface**, with 3 recorded gaps (see [phase log](phases/phase-09-hardening.md#9-exit-gate-first-pass)) | Tool approval (secret scanner); a git remote for `security-review` ([OQ-28](open-questions.md#oq-28)); staging for dynamic testing |
| 10 | Performance & load testing | **Prepared**: k6 installed, scripts written and validated locally, plan written ([phase log](phases/phase-10-performance-prep.md)) | **Not reached**: no measurements yet | Staging environment ([OQ-24](open-questions.md#oq-24)); targets and usage profile ([OQ-35](open-questions.md#oq-35)) |
| 11 | Integration, regression, release readiness | Not started | — | All above |

## 2. Approvals

| # | Request | Status | Reference |
| --- | --- | --- | --- |
| A1 | `git init`; commit the baseline | **Done.** Approved 2026-10-02 ("start next phase"). Baseline commit `020f811` on `main`. | [ADR-001](decisions/ADR-001-version-control.md) |
| A2 | `php artisan install:api` (adds Sanctum) | **Done.** Approved 2026-10-02. | [ADR-002](decisions/ADR-002-api-versioning-and-sanctum.md) |
| A3 | Add `larastan/larastan` (dev) | **Done.** Approved 2026-10-02. | [ADR-005](decisions/ADR-005-static-analysis.md) |
| A4 | `.ai/guidelines/` for agent rules | **Done.** Approved 2026-10-02. | [ADR-007](decisions/ADR-007-agent-guidelines.md) |
| A5 | Database engine; local test database | **Decided: MySQL** ("use mysql"). `jahez` and `jahez_testing` created on the local server (MariaDB 10.4). Production version open. | [ADR-004](decisions/ADR-004-database-mysql.md), [OQ-24](open-questions.md#oq-24) |
| A6 | Build the DOC reference-data tables and seeders | **Done.** Approved 2026-10-02 ("ok, and complete"). | [ADR-010](decisions/ADR-010-reference-data.md) |
| A7 | Answer [OQ-23](open-questions.md#oq-23) (Arabic-only or bilingual) | **Not answered.** Interim applied: Arabic authoritative, English only where DOC prints it, nullable `*_en` columns. | [ADR-010](decisions/ADR-010-reference-data.md) |
| A8 | Build the audit log and the first hardening pass while Phases 4–7 wait for input | **Done.** Approved 2026-10-02 ("ok complete"). Branch `phase/08-09-audit-and-hardening`. | [ADR-012](decisions/ADR-012-audit-log.md), [Phase 9 log](phases/phase-09-hardening.md) |
| A9 | Install a dedicated secret scanner (gitleaks or trufflehog) | **Done.** Approved 2026-10-03. gitleaks 8.30.1: no leaks in the full history. | [Phase 9 log](phases/phase-09-hardening.md#11-follow-up-2026-10-03) |
| A10 | Install k6 for Phase 10 | **Done.** Approved 2026-10-03. k6 2.2.0. | [Phase 10 log](phases/phase-10-performance-prep.md) |
| A11 | Install MySQL 8.4 locally | **Done.** Approved 2026-10-03. MySQL 8.4.9 on `127.0.0.1:3307`; the suite passes on it. | [Phase 10 log](phases/phase-10-performance-prep.md), [ADR-004](decisions/ADR-004-database-mysql.md) |
| A12 | Answer [OQ-34](open-questions.md#oq-34): API-only? | **Done.** Yes (2026-10-03); web routes and front-end tooling removed. | [ADR-013](decisions/ADR-013-api-only.md) |
| A13 | Phases 4–7 owner decisions (2026-10-03): factory-initiated marketplace (OQ-03); providers visible only after IMC approval; eligibility = offers the service and targets one of the factory's sectors; offer prices in EGP only, informational; workbook rows 59–60 are one service; Phase 5 = manual IMC classification only; self-edit for provider and factory members; approved providers stay approved after edits | **Applied.** Branch `phase/04-07-marketplace`. | [ADR-014](decisions/ADR-014-catalog-and-provider-profiles.md), [ADR-015](decisions/ADR-015-marketplace-requests.md), [ADR-016](decisions/ADR-016-manual-factory-classification.md) |
| A14 | Commit the staged Phase 10 preparation first | **Done.** Commit `e921f24`. | [Phase 10 log](phases/phase-10-performance-prep.md) |
| A15 | Classify factories through the digital readiness assessment (owner-supplied framework document); factories self-assess; categories separate from the DOC tiers; one recommendation line mapped to both catalog services of the same name; the source .docx copied into `docs/` | **Applied** 2026-10-03, branch `phase/04-07-marketplace`. Supersedes the A13 decision "Phase 5 = manual IMC classification only". | [ADR-018](decisions/ADR-018-digital-readiness-assessment.md), [Phase 5b log](phases/phase-05b-readiness-assessment.md) |
| A19 | Owner brief "Dynamic Assessment Administration, Card-Based Listings & Realistic Demo Data" (2026-10-04): a seven-part assessment administration area; questionnaire versions keep the source shape (owner decision); editable roadmap recommendations; version actors and answer text snapshots; no correction workflow for stored assessments (owner decision, OQ-51); factory and service listings as cards with a lean factory list; an explicit, idempotent demo seeder driven through the API | **Applied** 2026-10-04 (uncommitted) | [ADR-018 addendum 2](decisions/ADR-018-digital-readiness-assessment.md#addendum-2-2026-10-04-locked-shape-editable-roadmap-and-history), [ADR-021 amendment](decisions/ADR-021-ministry-administration.md), [ADR-024](decisions/ADR-024-demo-data.md), [log](phases/phase-assessment-admin-cards-demo-data.md) |
| A18 | Owner brief "Phase 2 & 3 Gap Closure and Production-Readiness Verification": API-backed landing-page announcements; rejected-listing resubmission; catalog activation reviewed and not added (ADR-010/014); Postman brought up to date; mail readiness; MariaDB and MySQL runs | **Applied** 2026-10-04 (uncommitted) | [ADR-022](decisions/ADR-022-announcements-and-listing-resubmission.md), [log](phases/phase-gap-closure-04.md) |
| A17 | Owner brief "Phase 3 — Complete Ministry/Admin Portal, Approvals, Notifications & End-to-End Verification": separate provider and factory approval pages with corrections requested; factory account approval; per-service listing review; readiness results and analytics; notifications for the new events; mail verification | **Applied** 2026-10-04 (uncommitted). Consequences of factory approval PROPOSED ([OQ-46](open-questions.md#oq-46)) | [ADR-021](decisions/ADR-021-ministry-administration.md), [log](phases/phase-administration-03.md) |
| A16 | Owner brief "Phase 1 — Identity, Onboarding, Profiles & Assessment Foundation": public factory and provider registration; provider and factory profile details, logo and registration documents; reviewed changes to verified legal information; admin management of the readiness questionnaire; separate public and portal layouts in the web client | **Applied** 2026-10-03 (uncommitted). Revises ADR-011 (no public sign-up). | [ADR-019](decisions/ADR-019-self-registration-documents-and-legal-changes.md), [ADR-018 addendum](decisions/ADR-018-digital-readiness-assessment.md#addendum-2026-10-03-questionnaire-administration-and-duplicate-submissions), [log](phases/phase-onboarding-01.md) |

### Decisions for Phase 3 (authentication and authorization): applied

All four recommended defaults were accepted ("ok, complete", 2026-10-02) and implemented in Phase 3. They remain revisable through the linked open questions.

| # | Decision | Recommended default | Open question |
| --- | --- | --- | --- |
| D1 | How do accounts come into existence? | **No public self-registration.** IMC staff create factory and provider accounts (or send invitations). The first IMC admin is created with an artisan command. Self-registration can be added later. | [OQ-18](open-questions.md#oq-18) |
| D2 | Which API clients, and therefore which auth mode? | **Sanctum bearer tokens** with an expiry (for example 8 hours) and `sanctum:prune-expired`. This works for a web SPA, mobile and integrations. Switch to SPA cookie mode only if a first-party SPA on the same domain is confirmed. | [OQ-22](open-questions.md#oq-22) |
| D3 | IMC roles for the first release | **One `imc_admin` role** with named permissions (for example `providers.evaluate`, `tiers.assign`), so it can later be split into the DOC §7 departments without code changes. Factory and provider members get one role each, scoped to their organization. | [OQ-21](open-questions.md#oq-21) |
| D4 | Minimum organization fields | `name` only, plus sectors (DOC §1). Everything else waits for the workbook ([OQ-01](open-questions.md#oq-01)/[OQ-19](open-questions.md#oq-19)/[OQ-20](open-questions.md#oq-20)). | [OQ-19](open-questions.md#oq-19), [OQ-20](open-questions.md#oq-20) |

## 3. Phase details

Each phase writes `docs/phases/phase-XX-*.md` (scope, decisions, changed files, commands, results, open issues, gate).

### Phase 1: Foundation & quality gates ✅
- **Delivered 2026-10-02.** See [phases/phase-01-foundation.md](phases/phase-01-foundation.md). Not delivered: CI (no remote yet, [OQ-28](open-questions.md#oq-28)) and a Newman run (not installed).
- **Original scope:** `/api/v1` route group in `routes/api.php`. A JSON error envelope for `api/*` (`withExceptions` + `shouldRenderJsonWhen`). A request-ID middleware (`Context`). A readiness check through the `DiagnosingHealth` event that reveals no infrastructure details. `Model::preventLazyLoading()` outside production. Publish `config/cors.php` and drop the wildcard default. Pest bootstrap: `LazilyRefreshDatabase`, `Http::preventStrayRequests()`, `Sleep::fake()`. Pint (plus Larastan if A3 is approved). A safe `.env.example`. CI workflow only if a remote exists ([OQ-28](open-questions.md#oq-28)).
- **Docs:** `README.md` (replace the skeleton text), `docs/README.md`, `docs/api-conventions.md`, `docs/testing/strategy.md`, `docs/troubleshooting.md`, ADRs 001/002/005/007, `.ai/guidelines/jahez.md`.
- **Tests:** health/readiness smoke; the error envelope for 404, 405, 422 and 500, with `APP_DEBUG=false` showing no trace, SQL or exception class; validation error shape; request-ID header present. Run Pint, static analysis and the full suite.
- **Acceptance:** a new developer can set up from the README and pass `php artisan test --compact`, Pint and static analysis.
- **Does not depend on** any business open question.

### Phase 2: Data model & migrations ✅ (approved scope)
- **Delivered 2026-10-02:** 7 reference tables (`pathways`, `pathway_levels`, `pathway_scope_items`, `level_provider_requirements`, `maturity_tiers`, `sectors`, `evaluation_criteria`), their models, and idempotent seeders with DOC text, including `DatabaseSeeder` hardening. See [phases/phase-02-data-model.md](phases/phase-02-data-model.md) and [data-model.md §4](data-model.md#4-reference-data-seeded-in-phase-2).
- **Carried forward:** organization tables to Phase 3/4 (D4), and the catalog to Phase 4 once the workbook arrives.
- **Blocked:** catalog ([OQ-01](open-questions.md#oq-01)), organization profile fields ([OQ-19](open-questions.md#oq-19), [OQ-20](open-questions.md#oq-20)), engagements/contracts/finance ([OQ-03](open-questions.md#oq-03), [OQ-15](open-questions.md#oq-15)–[OQ-17](open-questions.md#oq-17)).
- **Tests:** fresh migrate on a clean DB; rollback where it is safe; FK, unique and nullability behaviour; running a seeder twice creates no duplicates; seeded rows match DOC text exactly; criteria weights total 100.
- **Acceptance:** no catalog or business data is fabricated; every seeded row traces to a DOC page.

### Phase 3: Authentication, authorization, account lifecycle ✅
- **Delivered 2026-10-02:** see [phases/phase-03-auth.md](phases/phase-03-auth.md), [api-endpoints.md](api-endpoints.md), [roles-permissions.md](roles-permissions.md), [security/threat-model.md](security/threat-model.md). Not built (not required by D1): self-registration, email-verification flow (setting the password verifies the email), MFA ([OQ-33](open-questions.md#oq-33)).
- **Original plan:** sign-in/out, token or session lifecycle per [OQ-22](open-questions.md#oq-22) (with Sanctum tokens: set an expiry and schedule pruning); password reset; email verification only if required; IMC/factory/provider account families; memberships; policies; audit of auth events. Registration and approval flow per [OQ-18](open-questions.md#oq-18).
- **Tests:** valid and invalid credentials; generic login failure messages (no account enumeration); rate limits; expiry and revocation; 401 vs 403 vs 404; **IDOR/BOLA across two factories and two providers**; role escalation and mass assignment (`role`, `organization_id` in the payload are ignored); reset-token expiry and reuse.
- **Acceptance:** every protected endpoint has positive and negative authorization tests, and the policy permission matrix is tested at the policy level.

### Phase 4: Catalog, provider profiles, provider evaluation ✅ (approved scope)
- **Delivered 2026-10-03:**
  - the catalog from the workbook (7 categories, 42 services, with a test that reads the xlsx itself);
  - the workbook's provider fields, editable by the provider's own members;
  - IMC approval (pending, approved, rejected, suspended; a reason to reject or suspend);
  - the provider directory (approved providers targeting the factory's sectors; allow-listed filters and sort; no contact details).

  See [phases/phase-04-catalog-providers.md](phases/phase-04-catalog-providers.md) and [ADR-014](decisions/ADR-014-catalog-and-provider-profiles.md).
- **Not built:** the weighted evaluation and per-level eligibility ([OQ-13](open-questions.md#oq-13), [OQ-14](open-questions.md#oq-14)).
- **Original scope:** the catalog exactly as WB lists it; provider profile fields confirmed from WB; per-level eligibility ([OQ-14](open-questions.md#oq-14)); the evaluation workflow with DOC weights, where pass/fail waits for [OQ-13](open-questions.md#oq-13); allow-listed search, filter and sort; pagination.
- **Tests:** CRUD with ownership; validation of URL, email, phone and text limits; approval and visibility rules; injection attempts in sort/filter; N+1/query-count checks; the catalog seeder matches WB row by row.

### Phase 5: Factory profiles, assessments, pathways ✅ (approved scope)
- **Delivered 2026-10-03:**
  - factory members edit their own factory's name and sectors;
  - append-only manual IMC classification: a tier, a justification and a date, with the source pathway shown, and no score ([ADR-016](decisions/ADR-016-manual-factory-classification.md)).

  See [phases/phase-05-factory-assessments.md](phases/phase-05-factory-assessments.md).
- **Not built:** scoring and questionnaires ([OQ-06](open-questions.md#oq-06), [OQ-07](open-questions.md#oq-07)), infrastructure and cyber audit, baseline indicators, roadmaps ([OQ-10](open-questions.md#oq-10)–[OQ-12](open-questions.md#oq-12)), more factory fields ([OQ-19](open-questions.md#oq-19)).
- **Original scope:** factory profiles; versioned assessment methodology; assessments with raw inputs; tier assignment, which stays **manual with a justification** until [OQ-07](open-questions.md#oq-07); infrastructure & cyber audit; baseline indicators; roadmaps.
- **Rule:** no official score formula is coded until the owner approves one ([OQ-06](open-questions.md#oq-06)). Calculation tests use only owner-approved examples.
- **Tests:** incomplete-assessment behaviour; cross-factory isolation; history and versioning; boundary values; no NaN or division by zero.

### Phase 6: Engagements, workflows, contracts ✅ (marketplace; states PROPOSED)
- **Delivered 2026-10-03:** the factory-initiated marketplace ([ADR-015](decisions/ADR-015-marketplace-requests.md), [workflows.md](workflows.md)):
  - a request to 1–20 eligible providers;
  - each provider accepts or declines independently;
  - private messages and versioned offers, in EGP and informational;
  - the factory accepts one latest offer, which awards the request and closes the other threads.

  Locks are taken in a fixed order inside transactions. `based_on_version` stops stale offers and duplicates. A suspended provider's threads are paused. See [phases/phase-06-requests-offers-negotiation.md](phases/phase-06-requests-offers-negotiation.md).
- **Not built:** contracts ([OQ-17](open-questions.md#oq-17)). An agreed offer is not a contract, an invoice or a payment.
- **Original scope:** decided by [OQ-03](open-questions.md#oq-03). A server-enforced state machine covering DOC §7 steps. Transactions + `lockForUpdate` + idempotency keys for state changes. Contracts are labelled **non-binding drafts** until [OQ-17](open-questions.md#oq-17).
- **Tests:** valid and invalid transitions; concurrent acceptance (only one wins, **run on MySQL/MariaDB**, because SQLite has no row locks); duplicate submits; edits after a terminal state are refused; rollback when a step fails midway.

### Phase 7: Billing, payments, revenue rules ⛔ BLOCKED
- **2026-10-03, first pass:** nothing built.
- **2026-10-03, completion (owner instruction):** agreements, contract drafts, invoices and payments built as boundaries ([ADR-017](decisions/ADR-017-agreements-contracts-billing.md)). Every rule is a setting with no default; unset, the operation is refused. The decisions that unblock them are listed in [phases/phase-07-billing-payments.md](phases/phase-07-billing-payments.md#3-decisions-that-unblock-the-money-operations).
- **Only after [OQ-15](open-questions.md#oq-15) and [OQ-16](open-questions.md#oq-16) are answered.** A payment-gateway contract interface; DECIMAL amounts plus currency; documented rounding; safe invoice numbering; idempotent webhooks with signature and replay checks. No hard-coded percentages.

### Phase 8: Notifications, audit, reporting, admin (audit slice ✅)
- **Delivered 2026-10-03 (audit slice):** `audit_logs`, 16 security events written in the same transaction as their change, and `GET /api/v1/audit-logs` for administrators. See [phases/phase-08-audit-log.md](phases/phase-08-audit-log.md) and [ADR-012](decisions/ADR-012-audit-log.md).
- **Still to do:** notifications per [OQ-26](open-questions.md#oq-26); DOC §8 KPI reports per [OQ-27](open-questions.md#oq-27) with timezone-aware date ranges; audit retention per [OQ-25](open-questions.md#oq-25).
- **Original scope:** queued notifications with retries; an append-only audit log (actor, action, target, request ID, timestamp; no secrets); DOC §8 KPI reports per [OQ-27](open-questions.md#oq-27); timezone-aware date ranges.

### Phase 9: Security hardening (first pass ✅)
- **Delivered 2026-10-03 (first pass):**
  - security headers;
  - no storage route;
  - the `app:check-production` go-live gate;
  - a `finecomb` audit (16 findings) and a code review (15 findings), each fixed with a regression test or accepted with a reason ([security/findings.md](security/findings.md));
  - 41 mutation checks;
  - [deployment.md](deployment.md).

  See [phases/phase-09-hardening.md](phases/phase-09-hardening.md).
- **Still to do:** repeat over Phases 4–7 (uploads, workflows, payments); run a dedicated secret scanner (A9); run `security-review` once a remote exists; dynamic testing on staging.
- **Original scope:** route-by-route review; `composer audit` and `npm audit`; secret scan; security headers; CORS/CSRF matching the real client model; `docs/security/threat-model.md` and `security-test-matrix.md`; `finecomb` and `security-review` skills; every finding becomes a regression test.

### Phase 10: Performance & load testing
- **Scope:** k6 or Locust scripts in `tests/performance/`; staged load (smoke 10–50 VUs, then 100, then 250, only if justified) on a **Linux staging environment**, not the Windows/XAMPP dev host; a realistic read/write mix; p50/p95/p99, error rate and resource usage; `EXPLAIN` on critical queries; integrity checks after load. **No capacity claims without measured evidence.**

### Phase 11: Release readiness
- **Scope:** full regression on a clean DB; migration test on staging-like config; Postman/Newman smoke suite; production config checks (`APP_DEBUG=false`, HTTPS); deployment, rollback and backup docs; a release-readiness report with a PASS/FAIL per gate.

## 4. Cross-phase deliverables

- **Postman** (`postman/`): created in Phase 1 with only the endpoints that exist; extended every phase; never contains fabricated endpoints or real secrets.
- **Created in Phase 1:** `docs/README.md`, `api-conventions.md`, `testing/strategy.md`, `troubleshooting.md`, `decisions/` (ADR-001/002/004/005/007/009), `postman/`.
- **Created in Phases 4–6:** `workflows.md`, ADR-014 to ADR-016, and the phase logs 04–07. Each doc is created with the phase that has real content for it. Already created: `glossary.md` (P2); `roles-permissions.md` and `security/*` (P3); `deployment.md` and `security/findings.md` (P9; `deployment.md` is verified on a real host in P11); `performance/plan.md` and `performance/results/` (P10 preparation).

## 5. Risk register

| # | Risk | Likelihood / impact | Mitigation |
| --- | --- | --- | --- |
| RK-01 | The services workbook is missing, so catalog or profile fields get invented | **Resolved** (2026-10-03): the workbook had been in `docs/` since the baseline commit; the catalog is seeded from it and a test compares every row with the xlsx | Re-check `docs/` for supplied sources at the start of every phase |
| RK-02 | Operating model mismatch: the master prompt assumes a marketplace, DOC describes IMC-mediated selection | **Resolved** (2026-10-03): the owner confirmed the factory-initiated marketplace ([OQ-03](open-questions.md#oq-03)) | — |
| RK-03 | No version control: lost work, no reviewable diffs | **Mitigated** (git since Phase 1; no remote yet) | Push to a remote once [OQ-28](open-questions.md#oq-28) is answered |
| RK-04 | Readiness scoring and tier thresholds are undefined | **Resolved** (2026-10-03): the owner supplied the framework document; scoring and categories implemented from it ([ADR-018](decisions/ADR-018-digital-readiness-assessment.md)) | Versioned questionnaire; answered versions are frozen; the source test compares the seeded data with the .docx |
| RK-05 | Conflicting revenue-share figures in DOC (§5 vs §6) | Certain / High | Phase 7 blocked; no defaults ([OQ-15](open-questions.md#oq-15)) |
| RK-06 | Legal validity of electronic three-party contracts | Medium / High | Draft-only labelling until [OQ-17](open-questions.md#oq-17) |
| RK-07 | SQLite tests hide MySQL locking and constraint behaviour | **Mitigated** (tests run on a MySQL-protocol server since Phase 1) | — |
| RK-08 | Local server is MariaDB 10.4 (end-of-life), while the target is MySQL | **Mitigated** (2026-10-03): MySQL 8.4.9 runs locally and the full suite passes on it | Run the suite on MySQL 8.4 (`DB_PORT=3307`) for Phase 6 concurrency work and before each release; CI once a remote exists ([ADR-004](decisions/ADR-004-database-mysql.md)) |
| RK-09 | No Redis locally | Medium / Low | DB-backed cache, queue and locks work; Redis is optional later |
| RK-10 | Performance results from the Windows dev host are not representative | High / Medium | Run load tests only on Linux staging (Phase 10) |
| RK-11 | `boost:update` overwrites `AGENTS.md` customisations | **Mitigated** (`.ai/guidelines/`, Phase 1) | — |
| RK-12 | CORS wildcard default on `api/*` | **Mitigated** (deny-by-default allow-list, Phase 1, tested) | — |
| RK-16 | Error responses leak internals (Laravel's default 404 JSON includes the model class name) | **Mitigated** (ApiExceptionRenderer, Phase 1, tested) | Keep `ErrorResponseTest` green |
| RK-13 | Sanctum tokens never expire by default | **Mitigated** (8-hour expiry, pruning scheduled, misconfiguration fallback; Phase 3) | — |
| RK-17 | Invitation and reset emails depend on a running queue worker; with the `sync` queue the forgot-password timing protection is lost | Medium / Medium | **Gate in place (P9):** `app:check-production` fails on `sync`; [deployment.md](deployment.md) requires a supervised `queue:work` |
| RK-18 | `MAIL_MAILER=log` writes reset links (valid tokens) to the application log | Medium / High in production | **Gate in place (P9):** `app:check-production` fails on the `log` and `array` mailers |
| RK-20 | The audit log grows without limit until a retention period is decided | Certain / Medium | Indexed for its filters; retention and purging wait for [OQ-25](open-questions.md#oq-25); size to be measured in P10 |
| RK-21 | Tests run on MariaDB 10.4, while production targets MySQL 8, whose behaviour differs (for example, MySQL 8 reorders JSON object keys; CR-14, reproduced) | **Mitigated** (2026-10-03) | Tests avoid order-dependent JSON assertions; the suite passes on both engines |
| RK-19 | Per-account login lockout lets an attacker lock a known account out for 15 minutes | Medium / Low | Accepted trade-off against distributed brute force; values to be confirmed ([OQ-33](open-questions.md#oq-33)) |
| RK-14 | Arabic text: collation, search and length validation | Medium / Medium | `utf8mb4`, `mb_*`/`Str` helpers, Arabic test fixtures |
| RK-15 | DOC is a business concept paper, not a software spec, so assumption creep is likely | High / High | `SOURCE-REQUIRED`/`PROPOSED`/`OPEN-QUESTION` labelling; traceability matrix review each phase |
| RK-22 | The marketplace states are PROPOSED; owner answers to [OQ-38](open-questions.md#oq-38)–[OQ-40](open-questions.md#oq-40) may change the API (multiple awards, offer withdrawal, expiry, suspension policy) | Medium / Medium | States isolated in two enums with `canBecome()`; transitions are explicit endpoints, so a change adds or removes actions rather than reshaping payloads |
| RK-23 | Concurrency is verified by the lock sequence (SQL log) and sequential state guards, not by two truly concurrent requests: the test transaction (`LazilyRefreshDatabase`) keeps other connections from seeing test data | Medium / Medium | Lock order fixed in `ProviderRequest`; repeat with real parallel clients (k6) on staging in Phase 10 |
| RK-24 | Offer prices look like commercial terms but nothing is binding; clients might present an agreed offer as a contract | Medium / High | Docs and the API say so (`agreed` is not a contract); no contract, invoice or payment tables; a test checks none is created ([OQ-17](open-questions.md#oq-17)) |
