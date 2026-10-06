# Phase log: readiness-based eligibility and transformation plans (owner brief, 2026-10-05)

Brief: "Jahez — Readiness-Based Service Eligibility, Ministry Roadmap Orchestration & Factory Roadmap UI". Decision record: [ADR-025](../decisions/ADR-025-readiness-eligibility-and-transformation-plans.md).

**Nothing was committed, pushed or deployed.** The development database `jahez` on MariaDB (port 3306) was not touched. Every test, migration, seed and browser check ran on MySQL 8.4 (port 3307), against `jahez_testing` (the suite) or scratch databases created for this work and dropped afterwards: `jahez_roadmap_scratch`, `jahez_e2e_roadmap` and `jahez_postman_adr025`.

## 1. Inspected first

- **Docs read:** ADR-014, -015, -017, -018 (with addenda), -020, -021, -023, -024; `open-questions.md` (OQ-08, OQ-12, OQ-17, OQ-38 to OQ-46, OQ-51); `workflows.md`.
- **Code read:**
  - the eligibility call sites: catalog, categories, listings, provider directory and logo, request creation, adding providers;
  - the request, thread, agreement, IMC review and contract states;
  - the policies;
  - the test helpers and the demo seeder;
  - the web client's factory, IMC and readiness pages.
- **Working tree at the start:** an earlier, interrupted session on the same brief had left edits in 9 tracked files: routes, the `Permission`, `AuditEvent` and `NotificationEvent` enums, `AuditLog`, `ServiceRequest`, `ServiceListingController`, `ServiceRequestController` and `AddServiceRequestProvidersRequest`. They referenced about 45 classes and migrations that did not exist, so the request and listing endpoints could not be built. The edits were kept, and everything they reference was written in this session.

## 2. What the audit found

| Area | Before | Gap |
| --- | --- | --- |
| Readiness score and level | Correct (ADR-018): server-side, 10–40, four categories with stable codes, append-only history; boundary totals already tested in `LifecycleTest` | None. The level was not used for eligibility |
| Service eligibility | Approved provider + approved listing + shared sector, copied across ~5 call sites | The level played no part. Factories without an assessment saw everything. No IMC control per level |
| Catalog activity | No active flag (owner decision A18) | Per-level `is_active` added; the catalog stays reference data |
| Roadmap | Per-category recommendation lines of the questionnaire (ADR-018 §5) | No per-factory plan, stages, dependencies, versions or execution |
| Delivery or completion | Not modelled; the workflow ends at agreement → IMC review → non-binding contract draft | Recording execution needs an owner decision (asked: IMC records it, OQ-52) |

## 3. Owner decisions taken in this session (asked during planning)

1. **Who records execution:** IMC only, until the ministry defines the procedure (OQ-52).
2. **Requests before prerequisites:** a factory may send a request for a plan item before its prerequisites are completed; only the *start* is gated.
3. **Assigned provider:** binding for the factory's request (OQ-54).
4. **Rollout:** enforce now with nothing seeded. Recommended services are shown as hints only; the demo seeder maps demo services through the API.

## 4. API changes

| Area | Change |
| --- | --- |
| Schema | 8 migrations (`2026_10_07_100001`–`100008`): `readiness_level_services`; `transformation_plans`, `_versions`, `_items`, `_stages`, `_stage_items`, `_dependencies`; `service_requests.transformation_plan_item_id`. CHECK constraints, composite FKs and unique markers as in ADR-025. All FKs restrict; explicit names where MySQL's 64-character limit required them. Up, down and up again verified on MySQL 8.4 |
| Eligibility | `App\Readiness\ServiceEligibility` is the single source. Catalog, categories, listings (`meta.eligibility_status`), directory (list, filters, profile services, logo), request creation and adding providers (re-checked in the transaction with a share lock on the level row) all use it. An architecture test checks that no other file calls `eligibleFor()` or `offering()` |
| Level services | `GET /readiness-levels`, `PUT` and `DELETE /readiness-levels/{level}/services/{catalogService}` |
| Factory eligibility | `GET /factories/{id}/service-eligibility[/{catalogService}/providers]` |
| Plans | `TransformationPlanDraft`, `TransformationPlanReview`, `TransformationPlanPresenter`, `DependencyGraph`, `RequestProgress`, `PlanItemRequests`; controllers for plans, versions and items; 24 routes |
| Requests | `transformation_plan_item_id` on create (validated, item locked, one live request per item); binding assigned provider on create and on adding providers; `ServiceRequestResource.transformation_plan_item_id` |
| Permissions | `readiness_services.manage`, `transformation_plans.view_any`, `transformation_plans.manage` (IMC role) |
| Audit, notifications | 11 audit events; 3 factory notifications (plan published, status changed, item updated) |
| Demo data | `DemoDataSeeder` maps each level's recommended services and each scripted request's service, through the API |

## 5. Web client

**Factory:**
- `/factory/roadmap` («خطة التحول الرقمي» in the sidebar): a vertical RTL timeline of stages. Each stage shows its goal, IMC instructions, dates, status and progress, with its services grouped as «خدمات يمكن تنفيذها بالتوازي» or «تبدأ بعد استكمال…».
- Service cards show the state, the provider and its logo, dependency chips, parallel services, the waiting reason, the request status, dates, and «طلب الخدمة» / «متابعة الطلب». A request opens `RequestFormModal`, linked to the item and limited to the assigned provider.
- Empty, suspended and closed states.
- The services and catalog pages show a «أكملوا تقييم الجاهزية» state before an assessment and an empty state for a level with no service. A service outside the level shows «غير متاحة لمستوى جاهزية منشأتكم». The assessment result greys out recommended services that are not available.
- The request detail links to the plan, and the dashboard journey links to the roadmap.

**IMC:**
- Services page: a «إتاحة الخدمات حسب مستوى الجاهزية» tab with four level cards (ranges, counts, warnings), a per-level list (toggle, remove, provider count, «مفعّلة بلا مزود مؤهل», recommended and multi-level badges), add from the catalog, recommended hints, and a catalog summary.
- Factory file: a «خطة التحول الرقمي» card to create or open the plan.
- `/admin/roadmaps`: plan cards with status, version and progress.
- `/admin/roadmaps/:id`, with these tabs:
  - **Published plan:** execution actions as the server allows them.
  - **Draft editor:** stages and services with order, assigned provider, dependencies, instructions, internal notes and dates; client-side hints; saves send the revision.
  - **Preview** as the factory will see it.
  - **Review and publish:** blocking problems and warnings from the server, plus the change note.
  - **Versions and history.**
  - Plan actions: suspend, resume, close, delete, discard draft.

## 6. Commands and results

| Command | Result |
| --- | --- |
| `php artisan migrate`, `migrate:rollback --step=8`, `migrate` on `jahez_roadmap_scratch` (3307) | Up, down and up again: all 8 DONE (after naming 5 long foreign keys) |
| `DB_PORT=3307 php artisan test --compact --parallel --processes=4` | **1367 passed (5532 assertions), 0 failed** (the earlier baseline was 1291; the new and changed tests are listed in section 8) |
| `vendor/bin/phpstan analyse` (Larastan level 8) | **0 errors** |
| `vendor/bin/pint --dirty --format agent` | passed |
| Front: `npx tsc -b`, `npx oxlint`, `npm test`, `npm run build` | tsc 0 errors; oxlint 0; **27/27** tests; build OK (existing chunk-size warning) |
| `node postman/run-collection.mjs … 400` on a fresh `migrate` + `db:seed` database (`jahez_postman_adr025`) | **254 requests, 955 assertions passed, 0 failed** |
| Browser: headless Chrome over CDP, Vite on 3150, API (`php -S`) on 8030 against `jahez_e2e_roadmap` (`migrate`, `db:seed`, `DemoDataSeeder`) | **55/55 checks**, run twice (before and after the final layout fix). See section 7 |

The first two browser runs failed in the script, not the application: a repeated `const` in the page context, and `\r\n` from `mysql.exe` in the SQL comparison. Both were fixed and the checks rerun.

## 7. Browser and integration checks (55)

- **Integration:**
  - The factory's catalog equals its level's active services in the database (16).
  - Its listings equal the eligible provider listings in the database (5).
  - A service outside the level is absent, 404 by direct call, and refused on request (422) whatever the payload says.
- **IMC levels:** four levels with their ranges; the Advanced list matches the database; a service without a provider is flagged; adding and removing a service from the UI is stored by the API.
- **IMC plan:**
  - Created from the factory file. Three stages, five services, two dependencies and an assigned provider were built in the editor and saved.
  - The order, dependencies and provider were reloaded from the API and matched.
  - The preview hides internal notes and shows the instructions.
  - The server review showed no blocking problem; the plan was published.
- **Factory roadmap:**
  - Only the token is in browser storage.
  - Three ordered stages; stage 1 groups two parallel services; the dependent service waits and says why; dependency and parallel chips; the assigned provider; no IMC notes; no execution button.
  - No horizontal overflow at 390 px.
- **Request from the roadmap:**
  - The request is linked to the right item, and the request page says so.
  - A sent request does not start the service.
  - After the provider's acceptance and offer and the factory's acceptance (API), the item shows awaiting approval. IMC cannot start it before approving (409).
  - After IMC approves, the item shows «جاهزة للبدء».
  - IMC starts it from the UI (stored); the factory sees «قيد التنفيذ».
  - Completion releases the dependent service, and progress shows 1 of 5.
- **Permissions:**
  - A provider is sent to `/forbidden` and gets 404 from the API; another factory gets 404.
  - The factory gets 403 on drafts, execution, versions and level administration.
- **Layout:** no horizontal overflow at 390 px on three IMC pages; no uncaught page exception.

## 8. Tests added or changed

- **New:**
  - `ServiceEligibilityTest` (11 tests, with datasets)
  - `ReadinessLevelServiceTest` (6)
  - `TransformationPlanTest` (13, with datasets)
  - `TransformationPlanExecutionTest` (10, including the lock order factory → plan → item → level row → providers)
  - `TransformationPlanPolicyTest` and `ReadinessLevelServicePolicyTest` (matrices)
  - `tests/Unit/DependencyGraphTest`
  - an architecture test for the single eligibility source
  - 3 `AuditTrailTest` cases covering the 11 events
  - Front: `tests/roadmap.test.ts` (7) and `tests/noLocalEligibility.test.ts` (2)
- **Pest helpers:** `availableTo()`, `everyServiceAvailable()`, `factoryWithEveryService()`, `planFixture()`, `planDraftPayload()`, `publishedPlan()`, `planItem()`. `marketplaceRequest()` now makes its service available.
- **Changed to the new rule, with no assertion weakened:**
  - Factories in `ServiceRequestTest`, `CatalogTest`, `ProviderDirectoryTest`, `AdministrationTest`, `OrganizationDocumentTest`, `PortalWorkflowTest`, `ProviderRequestTest` and `AuditTrailTest` get an assessment and level services.
  - The two lock-order tests include `readiness_level_services:share`.
  - The directory's profile of a provider with no approved listing is now 404 (`AdministrationTest`).
  - The directory's sort and search tests give their providers a listing.
  - `LifecycleTest` makes ERP available to Advanced through the API as an explicit step.
- **Postman:** folder `04a` (setup) and folder `21` added. Folder 05's three shape requests now run as IMC. Every other folder is byte-identical.

## 9. Not done or still open

- **OQ-52:** who reports execution, and the evidence for it. Interim: IMC records it; a completed item cannot be corrected.
- **OQ-53:** plans and open requests when a level changes. Interim: nothing is rewritten; a warning is shown.
- **OQ-54:** an assigned provider that declines. Interim: IMC reassigns through a new version.
- **OQ-55:** dates as commitments, costs and owners.
- **Still blocked by earlier questions:** binding contracts (OQ-17), expert validation of the self-assessment (OQ-08), and money operations (OQ-15/16).
- **No "item progress percentage"** inside a service: no data supports one. Stage and plan percentages are counts of recorded completions.
- **The catalog reference cache** in the web client lasts one browser session. A factory whose level changes during a session sees the category chips update after a reload; every list and detail is fetched from the API on each visit.
