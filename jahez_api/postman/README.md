# Postman Collection

| File | Purpose |
| --- | --- |
| `Jahez-API.postman_collection.json` | All implemented endpoints, with test assertions, as a runnable workflow |
| `Jahez-Local.postman_environment.json` | Local development (`http://localhost:8000`) with the **local demo accounts** |
| `Jahez-Staging.postman_environment.example.json` | Template for staging, with every value empty. Copy it and fill it in **outside the repository**. |
| `run-collection.mjs` | Minimal Node runner for this collection only (see below) |

**Rules**
- The collection contains only endpoints that exist. Update it in the same change that alters the API contract.
- Never commit real tokens, passwords or production URLs. Token variables are secret-type and empty in committed files. The local environment contains only the publicly known local demo password (`password`), which works only against a database seeded in `APP_ENV=local`.
- Never run the collection against production. Folders 02–04, 06–08, 10 and 11 create or change records (07 creates a readiness assessment on each run).
- The API allows 60 requests per minute per account, and one run sends up to 68 for the administrator and 62 for Factory A. Run the bundled runner with a 400 ms delay (below), or the Collection Runner with a 400 ms request delay; without one, the last requests of an account get 429.
- Folders 17 and 18 also change demo data: they promote and un-promote Provider P, add, reject, resubmit and approve a listing of Provider P (`ot_ics_cybersecurity.01`), suspend and reinstate the factory created in folder 03, and create, publish, unpublish and delete an announcement.

## Prerequisites (local)
1. `php artisan migrate && php artisan db:seed` with `APP_ENV=local`. This creates `test@example.com` (IMC administrator) and the demo members `factory-a@example.test`, `factory-b@example.test`, `provider-p@example.test` and `provider-q@example.test`, all with the password `password`. It also seeds the service catalog, approves Demo Providers P and Q, and assigns them services: P (food sector) is eligible for Factory A (food); Q (engineering and metal) is not.
2. `php artisan serve` (and `php artisan queue:work` if you want the invitation and reset emails processed).

## Folders (run in order)

| Folder | Requests | Notes |
| --- | --- | --- |
| 00 Health & API Info | 1 | Readiness |
| 01 Authentication | 8 | Logs in as admin, Factory A, Provider P and Provider Q; stores tokens and organization IDs; wrong password; forgot and reset password (generic responses) |
| 02 Users & Roles | 3 | Admin creates a factory member (invitation), lists accounts, deactivates the new one |
| 03 Factory Profiles | 5 | Admin list/create/rename; member views and renames own factory; stores `factory_b_id` |
| 04 Provider Profiles | 5 | Admin list/create (starts `pending`); member views and edits own profile (stays approved); member approving itself → 403; stores `provider_q_id` and `created_provider_id` |
| 04a Readiness Eligibility Setup (ADR-025) | 8 | Catalog ids (IMC sees all 42); Factory A sees no listing before an assessment (`eligibility_status: no_assessment`); Factory A completes the assessment (ten «ب», Basic); the administrator makes ERP (`erp_business_applications.01`), MES (`automation_ot.01`) and OT security (`ot_ics_cybersecurity.01`) available to Basic; a member reading the level administration → 403 |
| 05 Service Catalog | 5 | 7 categories and 42 services read by the administrator (a factory member sees only its level's services since ADR-025); category filter; `filter[eligible]` for a factory member; the same filter as admin → 422 |
| 06 Provider Approval & Directory | 8 | Approve the created provider; approve again → 409; suspend without a reason → 422; suspend; directory for Factory A (P listed, Q not, no contact details); filter by service and sort; another factory's sector → 422; directory as a provider → 403 |
| 07 Readiness Assessment | 11 | Questionnaire (stores the question and choice IDs); Factory A submits ten «ب» answers → 201, total 20, Basic (a `total_score` and `category` in the body are ignored); own history; admin reads the result; `current_readiness` on the factory; recommended providers (P listed, Q not); incomplete → 422; admin submits → 403; Factory B's → 404; manual classification → 405 (retired, ADR-018); legacy classifications readable |
| 08 Requests & Negotiation | 21 | Ineligible provider → 422; request to Provider P; inbox; competitor → 404; message before acceptance → 409; accept; accept again → 409; messages from both sides; offer v1; stale offer → 409; USD → 422; offers list; IMC reads offers → 403; accept the offer; request awarded; cancel awarded → 409; message after agreement → 409; thread history; the thread's `agreement_id` (stored); adding providers to an awarded request → 409 |
| 09 Reference Data & Discovery | 9 | Sectors, factory sizes, maturity tiers (no score range), pathways, evaluation criteria (weights); Provider P's directory profile (no contact details); Provider Q's → 404; admin factory filter; provider review queue |
| 10 Provider Review & Evaluation | 4 | Written evaluation (scores `not_configured`); scores without an approved scale → 422; provider reads its evaluations → 403; an approved provider asks for a review → 409 |
| 11 Agreements, Contracts & Billing | 16 | Agreement with terms (factory) and, for the IMC review, with terms and `imc_review` (admin, ADR-020); a contract draft before IMC approval → 409; a party reviewing → 403; rejecting without a reason → 422; IMC approves; deciding again → 409; outsider → 404; contract with one trainee → 422; contract draft (never binding); second draft → 409; cancel the draft; billing configuration (nothing available); drafting an invoice → 409 `policy_not_configured`; invoice list; callback for an unconfigured gateway → 404 |
| 12 Admin & Reports | 5 | Audit log: list (the next-page link keeps `per_page`); filter by event (successful logins hold no token); filter by subject (the factory created and renamed in folder 03); unknown filter → 422; forged cursor → 422 |
| 13 Authorization & Negative Tests | 9 | Cross-tenant reads and writes → 404; admin-only actions → 403; privilege escalation → 403; member reading the audit log → 403; provider creating a service request → 403; no token → 401 |
| 14 Validation & Edge Cases | 5 | 404/405 envelopes; request-ID echo and replacement; record ID with trailing characters → 404 |
| 15 Regression & Workflow | 3 | Login → logout → reuse of the revoked token → 401 |
| 16 Registration, Documents & Questionnaire Versions | 10 | Public registration options and multipart registration (202 whatever the email), missing fields → 422; onboarding on the factory; profile details; provider change-request queue; questionnaire versions (admin) and 403 for a member; assessment submission with an `Idempotency-Key` |
| 17 Portals: Listings, Promotions, Notifications & Reports | 23 | Eligible listings for Factory A (P, not Q, no review data); own listings with review status for P; promotions of P and of the ineligible Q: the P listing comes first with «إعلان», Q stays invisible; a provider promoting itself → 403; end, end again → 409; notifications, read one, another account's → 404, read all; thread read marks (IMC 403, competitor 404); marketplace report, bad range → 422; agreements filtered by review status; `PATCH /me` (role and email ignored); factory change-request queue (member 403) |
| 18 Ministry Administration & Announcements | 42 | Review summary (member 403); P lists a new service (pending, others keep their status); self-approval 403; reject without reason 422; suspend a pending listing 409; reject; competitor and IMC resubmission 404/403; resubmission → pending; again → 409; approve; review queues and filters; factory approval (self 403, missing reason 422, invalid transition 409, suspend, reinstate); review request of an approved factory 409; a sensitive change stays pending, reject without reason 422, self-approval 403, reject; readiness analytics (5 / 10 / 40, 10–40, the four ranges; member 403); current classifications; announcements: external link 422, draft invisible to the public, publish, public read without a token, delete when published 409, unpublish, 404 for visitors, delete; admin routes without a token 401 |
| 19 Financial Policies (ADR-023) | 17 | Logs in as the demo finance maker and approver; members 403; the role-only administrator cannot create (403); invalid rate 422; maker creates a revenue-share draft scoped to the provider created in folder 04 starting 2099-01-01, submits it, cannot approve it (403); the approver approves (scheduled); editing it 409; history; resolution for that provider in 2099; no tax policy today (Arabic reason); preview 409 with `missing_policies`; financial readiness of the agreement (blocked, with reasons) and 404 for another provider |
| 20 Assessment Administration & Factory Cards | 12 | Draft a questionnaire version (author recorded); adding a question → 422 (locked shape); a member editing it → 403; edit the roadmap (two lines, one mapped to an existing catalog service, one unmapped); versions with their authors (version 1: none); delete the draft; results within a score range, an inverted range → 422, one factory's results; factory list items carry no legal or contact details while `GET /factories/{id}` does; a member listing factories → 403 |
| 22 Listing Packages & Cart (ADR-027) | 11 | Provider P lists two ERP packages (the listing goes back to `pending`); a package without a price → 422; Factory A does not see the listing while it is reviewed; IMC approves; Factory A sees the packages; adds Provider P's ERP to the cart (package, monthly, 8 users) with an estimated total; a period the package has no price for → 422; a provider has no cart → 403; checkout sends one request whose thread keeps the choice; Provider P reads it; the cart is empty |
| 21 Readiness Eligibility & Transformation Plans (ADR-025) | 24 | Level overview; Factory A's eligibility (Basic); a service outside its level → 404 and a request for it → 422; the administrator creates Factory A's plan, a cycle → 422, saves two stages (ERP and MES in parallel, OT security after ERP), a stale revision → 409; Factory A cannot see the draft (404); publish; Factory A reads it without IMC notes; requests the ERP item (linked), a second live request → 409; start before an approved agreement → 409; Provider P accepts and offers, Factory A accepts, the plan shows `awaiting_approval`; IMC approves; Factory A recording execution → 403; IMC starts the item; Provider P reading the plan → 404; version history (Factory A → 403) |

Collection-level tests assert that every response carries `X-Request-Id`, `X-Content-Type-Options: nosniff` and a `Cache-Control` containing `no-store`.

The 429 limits (messages 30 per minute, offers 10 per minute per user) are not exercised here, because that takes 31 requests; the Pest suite covers them (`NegotiationTest`).

## Run in the Postman app
Import the collection and `Jahez-Local.postman_environment.json`, select **Jahez Local**, and run the collection with the Collection Runner (folders in order).

## Run with Newman (preferred CLI)
Newman is not a project dependency. With Node.js installed:

```bash
npx newman run postman/Jahez-API.postman_collection.json \
  -e postman/Jahez-Local.postman_environment.json \
  --reporters cli,junit --reporter-junit-export storage/logs/newman-report.xml
```

## Run with the bundled minimal runner
`run-collection.mjs` implements only the Postman scripting this collection uses (`pm.test`, `pm.expect` chains, `pm.environment`, `pm.response`). It is **not** a Newman replacement. It is an ES module (`.mjs`); the project has no `package.json` (ADR-013):

```bash
node postman/run-collection.mjs postman/Jahez-API.postman_collection.json postman/Jahez-Local.postman_environment.json http://localhost:8000 400
# the optional last argument waits 400 ms between requests (stays under 60 per minute per account)
```

## Verification status
- **Listing packages and the factory cart (2026-10-06, ADR-027):** folder 22 added (11 requests: packages back to review, a package without a price → 422, hidden while reviewed, IMC approval, packages shown to Factory A, add to cart, a period without a price → 422, a provider has no cart → 403, checkout, the provider reads the choice, the cart is empty). Ran against PHP's built-in server (`MAIL_MAILER=log`) and a fresh `migrate` + `db:seed` scratch database on MySQL 8.4 (`jahez_postman_adr027`): **997 assertions passed, 0 failed.**
- **Cumulative levels, progression and hidden scores (2026-10-06, ADR-026):** assertions in folders 04a and 07 now check that a factory member gets no total, ranges, pillar scores or points, and gets `readiness_level`. Ran `node postman/run-collection.mjs … 400` against PHP's built-in server (`MAIL_MAILER=log`) and a fresh `migrate` + `db:seed` scratch database on MySQL 8.4 (`jahez_postman_adr026`): **955 assertions passed, 0 failed.**
- **Assessment administration, cards and demo data (2026-10-04):** folder 20 added. Against PHP's built-in server with the default limit (60 per minute), a freshly migrated and seeded scratch database on the local MySQL 8.4 (`jahez_postman_run3`) and a 400 ms delay: **222 requests, 841 assertions passed, 0 failed.** A first run on another fresh database (`jahez_postman_run2`) had one failure in the new folder: the test used `to.be.at.least`, which this runner does not implement; the assertion was rewritten and the whole collection run again.
- **Phase 2 and 3 gap closure (2026-10-04):** folders 17 and 18 added and folder 11 updated for the IMC agreement review. Against `php artisan serve` with the default limit (60 per minute), a freshly seeded scratch database on the local MySQL 8.4 and a 400 ms delay: **193 requests, 733 assertions passed, 0 failed** (91 s). The unmodified collection failed before these changes: folder 11 (contract drafts now need IMC approval; reviewers now see terms) and folder 16, which the runner could not execute (multipart bodies, `that`/`lengthOf`/`property` chains; added to the runner), plus a runner defect that kept environment values as numbers instead of strings.
- **Readiness eligibility and transformation plans (2026-10-05, ADR-025):** `node postman/run-collection.mjs … 400` against PHP's built-in server and a fresh `migrate` + `db:seed` scratch database on MySQL 8.4: **254 requests, 955 assertions passed, 0 failed.**
- **Business-logic completion (2026-10-03):** `node postman/run-collection.mjs …` against PHP's built-in server and the seeded local database: **106 requests, 437 assertions passed, 0 failed.** (A run started within the same minute as the previous one hit the 60 per minute limit, 429; the rerun a minute later passed.)
- **Phase 10 preparation (2026-10-03):** `node postman/run-collection.mjs …`, with no `package.json` present, against PHP's built-in server and the seeded local database: **39 requests, 160 assertions passed, 0 failed.**
- **Phase 9 (2026-10-03):** `node postman/run-collection.js …` against `php artisan serve` (port 8765) and the seeded local database. **39 requests, 160 assertions passed, 0 failed.**
- **Phase 3 (2026-10-02):** 32 requests, 99 assertions passed, 0 failed. That run used an identical copy of the runner outside the project. Inside the project, the documented command failed until Phase 9, because the runner was CommonJS (finding FC-13).
- **Newman has not been run.** It is not installed, and downloading it needs approval.
