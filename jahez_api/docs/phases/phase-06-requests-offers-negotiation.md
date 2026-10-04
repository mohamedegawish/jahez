# Phase 6: Service Requests, Provider Answers, Negotiation and Offers

**Date:** 2026-10-03 · **Branch:** `phase/04-07-marketplace` (from `phase/10-tooling-and-api-only` @ `e921f24`) · **Result:** complete; the workflow states are PROPOSED; gate **PASS, with PROPOSED states** (§7)

Built together with Phases 4, 5 and 7 from one combined brief. §4 and §5 hold the verification for **all four phases** (one branch, one suite).

## 1. Scope

**Delivered:** the factory-initiated B2B marketplace ([ADR-015](../decisions/ADR-015-marketplace-requests.md); states in [workflows.md](../workflows.md)).
1. A factory member sends **one request** for a catalog service to **1–20 eligible providers**. Each provider must be approved, offer the service and target one of the factory's sectors.
2. **Each provider answers independently:** it accepts, which opens a private negotiation, or declines. The factory can withdraw the request from one provider or cancel the whole request.
3. **Negotiation:** private messages between the two parties, and **versioned offers** from the provider: scope, deliverables, duration, and a price in EGP.
4. The factory **accepts one provider's latest offer**. That thread becomes `agreed`, the request `awarded`, and every other open thread `closed`.

**An agreed thread is not a contract, invoice or payment** ([OQ-17](../open-questions.md#oq-17); Phase 7 is BLOCKED).

**Not delivered:** contracts, multiple awards, offer withdrawal, expiry, adding providers to an open request, reopening a cancelled one, and notifications. Each is listed as an owner question in [OQ-38](../open-questions.md#oq-38) or [OQ-26](../open-questions.md#oq-26).

## 2. Decisions

**Owner (2026-10-03):**
- **The marketplace model** (answers [OQ-03](../open-questions.md#oq-03)): factory-initiated; one or several providers; independent answers; private negotiation; acceptance is not a contract.
- **Eligibility:** approved, offers the service, and targets one of the factory's sectors.
- **Prices:** EGP only, informational.

**Technical (ADR-015), PROPOSED where they shape the workflow:**
- **Two levels:** `service_requests` (shared content, stored once) and one `provider_requests` thread per provider. Each provider's view and history stay separate, and a provider sees only its own thread.
- **States:** request `open → awarded | cancelled`; thread `pending → accepted → agreed`, with `declined`, `withdrawn` and `closed` as exits. Both enums have `canBecome()`, and both models change status only through `moveTo()` (409 otherwise). Clients never send a status.
- **Locking:** status changes lock the request row, then the thread rows, inside one transaction with their audit entry. Messages and offers lock only their thread. Creating a request share-locks the chosen providers while it re-checks eligibility.
- **Offers:** append-only versions with `based_on_version`, the latest version the provider saw. A stale or repeated submission gets 409, so retries are safe without an idempotency key. Only the latest version can be accepted.
- **A suspended provider's threads are paused**, not closed (409 on every forward step; leaving still works). This is PROPOSED, [OQ-40](../open-questions.md#oq-40), and was added after the code review (CR-26).
- **Privacy:** competing providers get 404 on each other's threads, messages and offers. IMC administrators see statuses but get 403 on messages and offers (PROPOSED, [OQ-39](../open-questions.md#oq-39)). The audit log stores statuses and offer version numbers, never message text, terms or prices.
- **Technical limits, not business rules:**
  - 20 providers per request;
  - texts of at most 5000 characters, reasons of at most 2000;
  - durations of 1–3650 days;
  - amounts of 0–999,999,999,999.99 with at most two decimal places;
  - 30 messages and 10 offers per minute per user.

## 3. Data model, endpoints, permissions and audit

- **Migrations:**
  - `create_service_requests_table`;
  - `create_provider_requests_table`, unique on (request, provider);
  - `create_provider_request_messages_table`;
  - `create_offers_table`: `DECIMAL(14,2)` price, `char(3)` currency, unique on (thread, version). It also adds `provider_requests.agreed_offer_id`.

  See [data-model.md](../data-model.md).
- **Endpoints:** `/service-requests` (list, create, show, cancel) and `/provider-requests` (list, show, accept, decline, withdraw, messages, offers, accept offer). See [api-endpoints.md](../api-endpoints.md).
- **Permission:** `service_requests.view_any`, for IMC oversight of statuses only.
- **Policies:** `ServiceRequestPolicy` and `ProviderRequestPolicy`. Every action authorizes in its Form Request, before validation.
- **Audit events:** `service_request.created`, `.cancelled`; `provider_request.accepted`, `.declined`, `.withdrawn`; `offer.submitted`, `offer.accepted`.

## 4. Tests and checks

### 4.1 Test suite (Phases 4–7 combined, final)

| Engine | Mode | Result | Duration on the dev host |
| --- | --- | --- | --- |
| MariaDB 10.4.32 (port 3306) | `php artisan test --compact` | **555 passed (1593 assertions)** | 98.43 s |
| MariaDB 10.4.32 | `--parallel --processes=4` | **555 passed (1593 assertions)** | 39.62 s |
| MySQL 8.4.9 (port 3307) | `DB_PORT=3307 php artisan test --compact` | **555 passed (1593 assertions)** | 86.59 s |
| MySQL 8.4.9 | `DB_PORT=3307 … --parallel --processes=4` | **555 passed (1593 assertions)** | 30.67 s |

The durations come from the Windows dev host and are not performance data. Before Phases 4–7 there were **284** tests ([Phase 10 preparation log](phase-10-performance-prep.md)); Phases 4–6 added **271**.

| Phase 6 file | Tests |
| --- | --- |
| `Api/V1/ServiceRequestTest` | 29 |
| `Api/V1/ProviderRequestTest` | 24 |
| `Api/V1/NegotiationTest` | 50 |
| `Policies/ServiceRequestPolicyTest` | 13 |
| `Policies/ProviderRequestPolicyTest` | 28 |
| `Unit/ServiceRequestStatusTest` | 9 |
| `Audit/AuditTrailTest` (marketplace group) | 6 |

**What they cover:**
- **Eligibility:** pending, other-sector and other-service providers → 422. Re-checked when saving: a provider suspended between validation and insert is refused. Providers are share-locked.
- **Ownership:** the factory comes from the account, never from the payload.
- **Privacy:** a provider sees only its own thread; a competitor or another factory gets 404; IMC sees statuses, and messages and offers return 403.
- **Transitions:** every allowed one, and every refused one → 409.
- **Messages:** allowed only while negotiating; length bounds; 30 per minute; never audited.
- **Offers:**
  - versions and `based_on_version` (no base, stale or unknown base → 409);
  - EGP only, at most two decimals, the DECIMAL bound, negative and text prices refused;
  - the factory cannot author offers;
  - `current`, `lapsed`, `superseded` and `accepted` states.
- **Acceptance:**
  - latest version only;
  - a second acceptance on the same or another thread → 409;
  - another thread's offer → 404;
  - the other threads are closed;
  - rollback when the audit write fails;
  - no contract, invoice or payment.
- **Suspended provider:** forward steps → 409; decline and withdraw still work; the thread resumes after re-approval.
- **Authorization before validation:** cancel, decline, withdraw and the message list.
- **Lock sequences:** checked through the SQL log (§4.5).

### 4.2 Static analysis and formatting
- `composer analyse` (Larastan level 8, no excluded paths): **No errors** (158 files).
- `vendor/bin/pint --dirty --format agent`: first run fixed `class_attributes_separation` in `ListProviderDirectoryRequest` and an unused import in `NegotiationTest`; the final run **passed** with no changes.

### 4.3 Dependency audit, secret scan and whitespace
- `composer audit`: **No security vulnerability advisories found.**
- gitleaks 8.30.1 `dir` over a copy of every changed and new file (125 files, final run after the last edit): **no leaks found.**
- `git diff --check HEAD`: clean. The same check on every untracked file (`git diff --no-index --check`): clean.
- Docs link check (relative links and anchors in `README.md`, `docs/**` and `postman/README.md`): **832 links, 0 broken**, after the last docs edit.

### 4.4 Live checks (dev database, PHP's built-in server on `127.0.0.1:8765`, started from `public/`)
- `php artisan db:seed` (local): the catalog seeded, and the demo providers approved with their services.
- **Postman:** `node postman/run-collection.mjs postman/Jahez-API.postman_collection.json postman/Jahez-Local.postman_environment.json http://127.0.0.1:8765` → **79 requests, 327 assertions passed, 0 failed**. The run included the new folders 05–08 and the changed folders 01, 03, 04 and 13. Newman is not installed and was not run.
- **k6 smoke:** `k6 run tests/performance/smoke.js -e BASE_URL=http://127.0.0.1:8765` → 42 of 42 checks, 0 of 40 requests failed, 5 iterations. This checks correctness only and makes no performance claim (see [OQ-35](../open-questions.md#oq-35)).

### 4.5 Concurrency: what is verified, and what is not
**Verified:**
- **Lock sequences**, from the SQL query log in tests:
  - accepting an offer: `service_requests` FOR UPDATE, then the thread FOR UPDATE, then the provider LOCK IN SHARE MODE, then the sibling threads FOR UPDATE;
  - a message or offer: the thread FOR UPDATE, then the provider LOCK IN SHARE MODE;
  - creating a request: the providers LOCK IN SHARE MODE.
- **State guards**, by sequential repeats: a second acceptance, a stale offer and a second cancel each get 409.
- **Atomicity**, by forcing the audit write to fail: nothing changes.
- **The suspend-while-creating race**, reproduced deterministically by suspending the provider from an `afterResolving` hook between validation and the controller.
- **Both engines:** all of the above pass on MariaDB 10.4 and MySQL 8.4.

**Not verified:** two truly concurrent HTTP requests.
- `LazilyRefreshDatabase` runs each test in an uncommitted transaction, so a second connection cannot see the test's rows. It would only wait on the test's own inserts.
- The plan's "second-connection lock test like `Models/AuditLogTest`" was therefore **not built**. This is a deviation from the plan, recorded as [RK-23](../implementation-plan.md#5-risk-register).
- Next step: concurrent clients (k6) against staging in Phase 10.

### 4.6 Failures during the phase (not hidden)
- **Architecture test:** the Pest `security` preset failed on an `assert()` in `StoreServiceRequestRequest`. It was replaced by `abort(403)`.
- **Datasets:** the closures in `ServiceRequestTest` were wrapped twice (`fn () => fn …`). A parameter typed `Closure` already receives the closure itself.
- **Larastan:** `ServiceRequestFactory` used `findOrFail` with a mixed id. It was replaced by `whereKey()->firstOrFail()`.
- **Environment:** after a session restart both database servers were down (connection refused). They were restarted, and the dev database was migrated again.

## 5. Verification

### 5.1 Mutation checks
Each mutation is one exact text replacement, followed by a run of the one test file that should catch it; the original is then restored.
- **First round:** after implementation, 30 mutations, all caught.
- **After the code-review fixes:** the full set of **46 mutations, 0 survived**. One mutation was retargeted because its code moved, and 16 were added for the fixes.
- **Restoration:** after the run, `app/`, `routes/` and `database/` were compared with a backup taken before it: **identical**.

| Phase | Mutation (control disabled) | Caught by |
| --- | --- | --- |
| 4 | Catalog text drifts from the workbook | `ServiceCatalogSourceTest` |
| 4 | Eligibility ignores approval · ignores sectors · admin directory shows unapproved · directory exposes the contact email · search unescaped · sector filter not limited to own sectors | `ProviderDirectoryTest` |
| 4 | Eligible-services filter open to admins | `CatalogTest` |
| 4 | Members cannot edit their own provider | `ServiceProviderTest` |
| 4 | Approval allows approved → rejected · rejection without a reason | `ProviderApprovalTest` |
| 5 | Members cannot edit their own factory | `FactoryTest` |
| 5 | Classification can be edited · dated in the future · recorded by the factory's own member · shows a score · backfilled classification becomes current | `FactoryAssessmentTest` |
| 6 | Ineligible providers accepted · factory taken from the payload · provider sees competitor threads · eligibility not re-checked when saving · providers not share-locked when saving · cancel not authorized | `ServiceRequestTest` |
| 6 | A declined thread can be accepted again · decline not authorized · withdraw not authorized | `ProviderRequestTest` |
| 6 | Messages while not negotiating · stale offer accepted · superseded offer accepted · other threads stay open after the award · request row not locked · any currency accepted · IMC reads negotiations · offer acceptance not atomic · message rate limit removed · suspended provider can still negotiate · approval read without a lock · suspended provider accepts a request · factory accepts a suspended provider's offer · messages lock the parent request · lapsed offer shown as current · message list not authorized | `NegotiationTest` |
| 6 | Offer price written to the audit log · cancellation not audited · decline reason not audited | `AuditTrailTest` |
| 6 | A final request can change status again | `Unit/ServiceRequestStatusTest` |

### 5.2 Code review
The `code-review` skill reviewed every change on the branch and reported 10 findings: **CR-26 to CR-35** in [findings.md](../security/findings.md#phases-46-code-review-code-review-skill-branch-phase04-07-marketplace-2026-10-03). Each was checked against the code. Every bug or security fix was written **test-first**, and its test failed for the expected reason before the fix:
- 14 new tests failed first: 422 instead of 404, 201 or 200 instead of 409, `current` instead of `lapsed`, and id order instead of date order.
- The 3 audit tests passed at once: the behaviour was already correct, and only the coverage was missing.

**Outcome:**
- **8 fixed:**
  - CR-26: suspension pauses threads;
  - CR-27: eligibility re-checked under a lock;
  - CR-28: authorization before validation;
  - CR-30: the `lapsed` offer state;
  - CR-32: classification order;
  - CR-33: `ServiceRequest::moveTo()`;
  - CR-34: thread-only locks for messages and offers;
  - CR-35: list requests extend `ListRequest`.
- **1 coverage gap closed:** CR-29, audit tests.
- **1 accepted as an owner question:** CR-31, a request left `open` once every provider has declined ([OQ-38](../open-questions.md#oq-38)).

## 6. Docs, Postman and changed files

**Docs:**
- New: [ADR-015](../decisions/ADR-015-marketplace-requests.md), [workflows.md](../workflows.md), this log and the logs for [Phase 4](phase-04-catalog-providers.md), [Phase 5](phase-05-factory-assessments.md) and [Phase 7](phase-07-billing-payments.md).
- [open-questions.md](../open-questions.md): OQ-03 answered; OQ-16 partly answered; OQ-38, OQ-39 and OQ-40 added.
- Updated: [api-endpoints.md](../api-endpoints.md), [api-conventions.md](../api-conventions.md) (money, action endpoints, the negotiation limiters), [data-model.md](../data-model.md), [roles-permissions.md](../roles-permissions.md), [requirements-traceability.md](../requirements-traceability.md), [architecture.md](../architecture.md), [implementation-plan.md](../implementation-plan.md), [threat model](../security/threat-model.md) (T22–T30), [security test matrix](../security/security-test-matrix.md), [findings.md](../security/findings.md), [testing strategy](../testing/strategy.md), [engineering-skills.md](../engineering-skills.md), `README.md`, `docs/README.md`.
- Agent rules: `.ai/guidelines/jahez.md`, then `php artisan boost:update --no-discover --no-interaction` to regenerate `AGENTS.md`.

**Postman:**
- Folders 05–08 added.
- Folder 01: Provider Q login. Folder 03: a member renames their own factory. Folder 04: self-edit, and self-approval → 403.
- Folder 13: the outdated "member updates own factory (403)" became a cross-tenant write (404), and "provider creates a request (403)" was added.
- Environments: `provider_q_email`, a secret `provider_q_token` (empty), and record-ID variables. The staging template stays empty.
- [postman/README.md](../../postman/README.md) updated.

**Code (Phases 4–6):**
- **Enums:** `ProviderApprovalStatus`, `ServiceRequestStatus`, `ProviderRequestStatus`; `AuditEvent` and `Permission` extended.
- **Models:** `ServiceCategory`, `CatalogService`, `FactoryAssessment`, `ServiceRequest`, `ProviderRequest`, `ProviderRequestMessage`, `Offer`; `ServiceProvider`, `Factory`, `User` and `AuditLog` changed.
- **Controllers:** `CatalogServiceController`, `ServiceCategoryController`, `ProviderDirectoryController`, `ServiceProviderApprovalController`, `FactoryAssessmentController`, `ServiceRequestController`, `ProviderRequestController`, `NegotiationMessageController`, `OfferController`; `ServiceProviderController` changed.
- **Form Requests:**
  - `ActionReasonRequest` (abstract), with `CancelServiceRequestRequest`, `DeclineProviderRequestRequest` and `WithdrawProviderRequestRequest`;
  - `ApproveServiceProviderRequest`, `ListCatalogServicesRequest`, `ListProviderDirectoryRequest`, `ListServiceRequestsRequest`, `ListProviderRequestsRequest`, `ListNegotiationMessagesRequest`;
  - `StoreFactoryAssessmentRequest`, `StoreServiceRequestRequest`, `StoreNegotiationMessageRequest`, `StoreOfferRequest`;
  - `Concerns/ValidatesProviderProfile`, and `Concerns/ValidatesCodeLists` (renamed from `ValidatesSectorCodes`);
  - changed: `ListRequest`, and the factory and provider store and update requests.
- **Resources:** `CatalogServiceResource`, `ServiceCategoryResource`, `ProviderDirectoryResource`, `FactoryAssessmentResource`, `ServiceRequestResource`, `ProviderRequestResource`, `NegotiationMessageResource`, `OfferResource`; `ServiceProviderResource` changed.
- **Policies:** `FactoryAssessmentPolicy`, `ServiceRequestPolicy`, `ProviderRequestPolicy`; `FactoryPolicy` and `ServiceProviderPolicy` changed.
- **Other:** `AppServiceProvider` (the two limiters); `routes/api.php`; 9 migrations; `ServiceCatalogSeeder`; `ReferenceDataSeeder` and `LocalDemoSeeder` changed; factories `ServiceRequestFactory` and `ProviderRequestFactory` (new) and `ServiceProviderFactory` (changed).
- **Tests:** the files in §4.1 and the [Phase 4](phase-04-catalog-providers.md#5-tests) and [Phase 5](phase-05-factory-assessments.md#4-tests) logs, plus `tests/Pest.php` (`marketplaceRequest()`, `offerVersion()`, `lockingReads()`).

## 7. Exit gate

| Criterion | Evidence | Result |
| --- | --- | --- |
| The owner's marketplace model implemented; no business rule invented | ADR-015; PROPOSED labels on states, limits, oversight and suspension (OQ-38 to OQ-40) | PASS |
| Valid and invalid transitions; terminal states locked; 409 envelope | `ProviderRequestTest`, `ServiceRequestTest`, `Unit/ServiceRequestStatusTest`; mutation-verified | PASS |
| Duplicate and stale submissions refused | `based_on_version` tests; second acceptance 409 | PASS |
| Concurrency on MySQL: transactions and row locks in a fixed order | Lock sequences in the SQL log; rollback test; both engines | PASS, with the limit in §4.5 (RK-23) |
| Tenant isolation and competitor privacy | 404 matrices (HTTP and policy); authorization before validation; mutation-verified | PASS |
| No contract, invoice or payment | Guard test; Phase 7 BLOCKED | PASS |
| Suite (both engines, serial and parallel), Larastan, Pint, `composer audit`, gitleaks, `git diff --check`, Postman | §4 | PASS |
| Workflow states confirmed by the owner | OQ-38 open | **PROPOSED** |

**Gate: PASS, with PROPOSED states.** The states, the IMC oversight scope and the suspension policy await the owner (OQ-38, OQ-39, OQ-40). True parallel-request testing waits for staging (RK-23).

## 8. Next

1. The owner answers OQ-36 to OQ-40, and OQ-15 to OQ-17 for Phase 7.
2. A `finecomb` pass over the Phase 4–6 surface; the Phase 9 repeat.
3. On staging: parallel-client tests of offer acceptance and cancellation (RK-23), then the Phase 10 measurements.
4. Notifications for requests and offers once [OQ-26](../open-questions.md#oq-26) is answered.

## 9. Business-logic completion (2026-10-03)

**Owner instruction:** complete the business domain and the API end to end. Isolate every unresolved rule as a setting or a provisional workflow, instead of blocking unrelated work, and never fabricate financial policy. Built in four committed units on this branch:

| Commit | Unit |
| --- | --- |
| `c5d55b5` | Reference data, discovery profiles, provider review and evaluations |
| `053ed90` | Marketplace completion: thread history, adding providers, offer validity, single-award setting |
| `8416170` | Agreements and contract drafts ([ADR-017](../decisions/ADR-017-agreements-contracts-billing.md)) |
| `626244b` | Billing and payment boundaries behind owner-set policies (ADR-017) |

### 9.1 What was added

- **Reference data:** `GET /reference/{sectors, factory-sizes, maturity-tiers, pathways, evaluation-criteria}`. The seeded source text, read-only.
- **Factories:**
  - the declared size, from a configurable list (OQ-04 interim, set by IMC);
  - `current_classification` on the factory resource;
  - IMC list filters: sector, size, literal search.
- **Providers:**
  - IMC list filters, including the review queue (`filter[approval_status]=pending`);
  - a rejected provider's re-review request (PROPOSED);
  - configurable required profile fields (OQ-36; none by default);
  - DOC §6 evaluations: written per criterion; scores, an exact weighted total and the pass-mark comparison only once the owner sets the scale and pass mark (OQ-13); IMC only; append-only.
- **Discovery:** `GET /provider-directory/{id}`, a provider's public profile (404 unless the factory could find it). Contact details sit behind a setting (OQ-37, off).
- **Marketplace:**
  - thread history (`/provider-requests/{id}/history`), recorded on every status change;
  - `POST /service-requests/{id}/providers` (PROPOSED), the way out when every provider declined; `active_provider_count` on requests;
  - offers may carry a `valid_until` the provider sets, giving an `expired` state that cannot be accepted;
  - the single-award rule is a setting (OQ-38, default on).
- **Phase 7 boundaries** ([Phase 7 log](phase-07-billing-payments.md)):
  - agreements, recorded with the acceptance;
  - contract drafts, never binding, with the DOC §6 knowledge-transfer commitment;
  - invoices: exact decimal money, gap-free numbers;
  - payments: an idempotent start; verified gateway evidence only; reconciliation.

  Every money operation answers 409 `policy_not_configured` until its rule is set.

21 endpoints were added (69 routes under `/api/v1`), and 12 migrations. The full list is in [api-endpoints.md](../api-endpoints.md) and [data-model.md](../data-model.md).

### 9.2 Provisional assumptions (all reversible, each with the open question that decides it)

| Assumption | Where | Decides |
| --- | --- | --- |
| A rejected provider may ask for one new review; only after a rejection | `/service-providers/{id}/review-request` | OQ-13, OQ-36 |
| Evaluations are internal to IMC (the provider gets 403) | `ServiceProviderPolicy::evaluate` | OQ-13 |
| The weighted total is Σ weight × score / scale, half up | `ProviderEvaluation::weightedTotal` | OQ-13 (the scale itself is unset) |
| Factory size is set by IMC, not by members | `UpdateFactoryRequest` | OQ-04, owner decision on self-edit |
| A factory may add providers to an open request; at most 20 per request | `ServiceRequestController::addProviders` | OQ-38 |
| The first accepted offer closes the other threads (setting) | `jahez.marketplace.single_award` | OQ-38 |
| Either party may draft a contract | `AgreementPolicy::draftContract` | OQ-17 |
| One invoice per agreement that is not cancelled | `InvoiceController::store` | OQ-16 |
| The invoice's first line is the agreed service at the agreed price; the factory pays | `InvoiceController::store`, `InvoicePolicy::pay` | OQ-16 |
| IMC billing oversight sees invoices in full; agreement and contract content stays hidden from IMC | `InvoicePolicy`, `AgreementResource`, `ContractResource` | OQ-39 |
| The revenue share applies to the subtotal before tax | `Invoice::issue` | OQ-15 |
| An unknown payment-gateway key is 404; a verified callback is always answered 200 | `PaymentCallbackController` | OQ-16 |

### 9.3 Tests and checks

| After | MariaDB 10.4 | MySQL 8.4 |
| --- | --- | --- |
| Unit 1 (`c5d55b5`) | 648 passed (1880 assertions) | 648 passed |
| Unit 2 (`053ed90`) | 676 passed (1955) | 676 passed (parallel) |
| Unit 3 (`8416170`) | 718 passed (2116, parallel) | 718 passed (parallel) |
| Unit 4 (`626244b`) | **823 passed (2572, parallel)** | **823 passed (parallel)** |

- **Static analysis and formatting:** Larastan level 8 clean after each unit; Pint clean.
- **Postman:** 106 requests, **437 assertions passed, 0 failed**, against the seeded dev database. The first rerun within the same minute hit the 60 per minute limit (429); a rerun a minute later passed.
- **Secret scan:** gitleaks over the four commits found no leaks.
- **Changed existing tests (not weakened):**
  - "creates no contract, invoice or payment" became "records the agreement, and creates no contract, invoice or payment". The `contracts`, `invoices` and `payments` tables now exist by design, so the test checks that an acceptance creates no rows in them.
  - The rollback test also checks that no agreement is left.
- **Failures during the work, fixed:**
  - JSON-path assertions on error keys containing dots: two tests now read the errors array directly.
  - Test helpers defined in one file but needed in another moved to `tests/Pest.php`.
  - `Agreement::sole()` in a helper failed when two agreements existed; the lookup is now scoped to the thread.
  - Larastan found mixed ids passed to `findOrFail()` and list shapes; the code was fixed, not suppressed.

### 9.4 Mutation checks

**28 mutations, 0 survived.** Each disabled one new control, ran the test file that should catch it, and restored the source. Afterwards `app/`, `routes/` and `database/` were identical to a backup taken before the run. The mutations:
- **Evaluations:** scores accepted without a scale; weighted total truncated instead of rounded; evaluations readable by the provider.
- **Provider review:** review request from any status; approval ignoring required fields.
- **Directory and factories:** directory profile ignoring eligibility; contact details always shown; members setting the factory size.
- **Marketplace:** providers added to a closed request; the same provider added twice; an expired offer accepted; the single-award setting ignored; a status change missing from the history.
- **Agreements and contracts:** no agreement recorded on acceptance; agreement terms shown to IMC; one trainee accepted; two contract drafts in force; the agreement not locked while drafting.
- **Invoices:** anyone who sees the agreement drafting invoices; the issuer defaulted when unset; tax truncated instead of rounded; the number sequence not locked; an issued invoice cancellable.
- **Payments:** an `Idempotency-Key` replay starting a new payment; a payment with a different amount applied; a repeated callback applied twice; a paid payment not paying the invoice; a callback accepted for any gateway key.

### 9.5 Not built, and why

| Behaviour | Blocker |
| --- | --- |
| Service discovery driven by a factory's classification (tier → services) | No source maps DOC tiers or pathway scope items to workbook services; inventing one is not allowed |
| Readiness scoring, questionnaires, infrastructure and cyber audit, baseline indicators, roadmaps, impact measurement | OQ-06, OQ-07, OQ-10, OQ-11, OQ-12 |
| More factory profile fields | OQ-19 (the workbook has none) |
| Hard provider eligibility per DX level | OQ-14 |
| Offer withdrawal, request expiry, automatic closing of a request nobody accepted | OQ-38 |
| Contract signature, approval, activation, termination; the knowledge-transfer memo attachment | OQ-17, OQ-10 (no document uploads) |
| Invoice drafting, issuing, payments in practice; refunds; payouts; credit notes; billing schedules; e-invoicing | OQ-15, OQ-16 (settings unset, no gateway adapter) |
| Notifications for requests, offers, approvals | OQ-26 |
| Parallel-request concurrency tests | RK-23 (needs staging) |
