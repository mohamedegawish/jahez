# Jahez API: Project Rules

Jahez is a JSON API for the Industrial Modernisation Centre (IMC) "Smart Industry – Ecosystem" initiative. It connects IMC staff, industrial factories and digital-transformation service providers.

## Read first
- Before starting any multi-step task, create a todo list with all steps.
- Mark each item as in_progress when you start it and completed when done.
- Keep the todo list updated throughout the task.
Before editing anything, read `README.md`, `docs/implementation-plan.md` (current phase, blockers, approvals), `docs/open-questions.md` and the documentation for the module you are touching. Run `git status` and read the related tests.

## Business rules

- The functional sources are listed in `docs/requirements-traceability.md`. Every business rule must trace to a source row, or be an approved decision recorded in `docs/decisions/`.
- Never guess an unresolved business, legal or financial rule (scoring formulas, tier thresholds, revenue-share percentages, fees, taxes, contract validity, required documents, retention periods). Record it in `docs/open-questions.md` and implement only the documented interim safe behaviour.
- Seed reference data with the exact Arabic source text. Never invent catalog entries or sub-services.
- Reference data (sectors, pathways and levels, scope items, provider requirements, maturity tiers, evaluation criteria, the readiness questionnaire) changes **only** through its seeders in `database/seeders/`, which must stay idempotent and cite the source page. Fill `*_en` columns only with English the source itself prints (interim for OQ-23). Never add maturity-tier score thresholds or revenue-share rules until they are approved. See `docs/decisions/ADR-010-reference-data.md`. Exception: readiness questionnaire versions after version 1 are managed by IMC administrators as drafts that are published, never edited once published (ADR-018 addendum).
- Tests that need reference data run `$this->seed(ReferenceDataSeeder::class)`. Do not create factories that invent sectors, tiers or criteria.
- At the start of each phase, check `docs/` for newly supplied sources (`git log --stat -- docs/`). The services workbook sat unnoticed in `docs/` from the baseline commit until Phase 4.

## Catalog and marketplace (Phases 4–6)

- The service catalog comes only from the services workbook (`ServiceCatalogSeeder`, ADR-014); `ServiceCatalogSourceTest` compares every row with the xlsx. Never add, rename or split catalog services by hand.
- A provider reaches factories only after IMC approval. Eligibility (ADR-025, ADR-026): the service must be available to the factory's level or a level below it (an active `readiness_level_services` row that IMC manages; none before the first assessment; nothing seeded; the level is the highest of the assessed category and the levels opened in `readiness_level_unlocks`), and the provider approved, offering the service through a listing IMC approved (ADR-021) and targeting one of the factory's sectors. `App\Readiness\ServiceEligibility` is the only code that combines `ServiceProvider::eligibleFor()` and `offering()` (an architecture test enforces it); every factory-facing path (catalog, listings, directory, eligibility, requests, plans) goes through it. Approval fields are never fillable; they change only through `POST /service-providers/{id}/approval`, listing statuses only through `POST /service-providers/{id}/services/{catalogService}/review` (IMC), `…/resubmit` (ADR-022) and `PUT …/packages` (ADR-027: a package or price change sends an approved or rejected listing back to `pending`; 409 when suspended), factory approval only through `POST /factories/{id}/approval` (never touching the readiness classification).
- Marketplace statuses change only through the action endpoints and `ServiceRequest::moveTo()` / `ProviderRequest::moveTo()` (the enums' `canBecome()`); clients never send a status. The states are PROPOSED (`docs/workflows.md`, OQ-38): update the workflow doc and ADR-015 with any new state or transition.
- Lock order: the `service_requests` row first, then its `provider_requests` rows (`ProviderRequest::lockWithServiceRequest()`, `closeOpenThreadsOf()`). Messages and offers lock only their thread (`lockThread()`). Call `ensureProviderApproved()` before any step that moves a thread forward.
- A provider sees only its own thread; competitors get 404. IMC administrators see statuses, never messages or offers (PROPOSED, OQ-39). Never write message text, offer terms or prices to the audit log.
- Offers are append-only versions checked with `based_on_version`. Prices are `DECIMAL(14,2)` decimal strings, EGP only, and informational. An agreed offer is not a contract, invoice or payment: add no contract, invoice, payment, fee or revenue-share tables or fields until OQ-15, OQ-16 and OQ-17 are answered (Phase 7 is BLOCKED).
- Financial and contract rules (issuer, numbering, taxes and fees, payment terms, revenue share, contract templates) are approved, versioned database policies (ADR-023), never config, env or frontend constants. Resolve them with `AppBillingPolicyResolver` inside the transaction that makes the record (share-locked), store the version ids and computed values on the record, and never recalculate it. Never seed a policy or a default value; tests create fixtures with `configureBilling()` / `approvedPolicyVersion()`. Change policy state only through `AppBillingFinancialPolicyLifecycle`; whoever prepared a version never approves it.
- Factories are classified only by the digital readiness assessment (ADR-018, source `docs/إطار تقييم مستوى الجاهزية الرقمية.docx`): the server sums the stored choice points (10–40) and picks the category of the version answered (B4 Automation 10–17, Basic 18–25, Advanced 26–33, Smart 34–40). Never accept a score, category or points from a client, and never add weights, normalisation or categories the source does not state. Assessments and answers are append-only; an answered questionnaire version is never changed in place (publish a new version). Manual classification (ADR-016) is retired and read-only. The readiness categories are separate from the DOC maturity tiers (OQ-41), whose score columns stay NULL.
- Listing packages (ADR-027) are informational EGP prices reviewed with the listing; never copy them into offers, agreements or invoices, and never write a price to the audit log. The factory cart (`service_cart_items`) only collects listings: `POST /cart/checkout` creates one service request per service through the same path as `POST /service-requests` (same eligibility re-check and locks, plus the cart rows for update) and copies the choice onto each thread (`provider_requests.selection`, parties only). No payment, reservation or invoice follows from a cart.
- Readiness scores (totals, ranges, pillar scores, answer and choice points) go only to `assessments.view_any` holders (`ReadinessAssessmentResource::showsScores()`); a factory member sees its category and level, never a score (ADR-026). Levels open only through `App\Readiness\LevelProgression`, called inside the IMC transaction that completes or cancels a plan item or publishes a version: when every non-cancelled published item whose service's lowest active level is the factory's level is completed, the next level is recorded in the append-only `readiness_level_unlocks` (never closed again, OQ-56), audited and notified.
- Transformation plans (ADR-025) are written by IMC only (`transformation_plans.manage`) through `App\TransformationPlans\TransformationPlanDraft` (versions; a draft save names its revision; publication blocked by `TransformationPlanReview`); item execution is recorded by IMC only and `start` needs completed prerequisites and an IMC-approved agreement (OQ-52). A sent request never starts an item; an assigned provider is binding; one live request per item. Never let a client set a plan or item status, and never show `internal_notes`, `change_note` or actors to a factory.
- Readiness recommendations of version 1 map only to existing catalog services with the same text (`ReadinessAssessmentSourceTest`); a line the catalog lacks stays unmapped (OQ-42). In later versions IMC maps lines to existing catalog services only; no catalog service is ever created for a recommendation. `filter[recommended]` narrows eligible providers and never replaces `ServiceEligibility`.
- Questionnaire versions keep the source shape (`App\Readiness\QuestionnaireShape`: 5 pillars × 2 questions × 4 choices worth 1–4, totals 10–40; ADR-018 addendum 2). Assessments are recorded only through `App\Readiness\ReadinessAssessmentRecorder`, which snapshots the answer text; no endpoint corrects a stored score or category (OQ-51).
- New paginated lists extend `ListRequest`; filters use `filter[...]` and sorting uses `sort=`, both allow-listed.

## API conventions

- Every API route lives in `routes/api.php` inside a version group (`/api/v1`). `tests/Feature/Api/ApiVersioningTest.php` enforces this.
- Errors use the envelope in `docs/api-conventions.md`, rendered by `App\Http\Responses\ApiExceptionRenderer`. Do not build error JSON by hand in controllers. Throw or `abort()` instead.
- Successful responses go through API Resources with an explicit field list. Never return a model directly.
- Organization and owner IDs come from the authenticated user, never from the request. A cross-tenant record must return 404.
- Constrain the format of every route parameter (record IDs: `->where(['user' => '[0-9]+'])`). MySQL would otherwise match `5abc` to record 5. `ApiVersioningTest` enforces this.
- Paginated lists call `->withQueryString()`, so page links keep `per_page` and the filters.
- The project is API-only (ADR-013): do not add web routes, Blade pages, sessions, cookie authentication or front-end tooling without a new ADR. Sanctum's routes stay disabled (`sanctum.routes = false`).

## Audit log

- Record every security-relevant change with `AuditLog::record()` **inside the same database transaction as the change** (ADR-012). Add an `App\Enums\AuditEvent` case and a test in `tests/Feature/Audit/AuditTrailTest.php`.
- Never pass passwords, tokens or secrets as metadata. Keys containing `password`, `token` or `secret` are dropped. Record only what changed (`from`/`to`), and nothing for a no-op.
- A queued job that writes an entry receives the client IP when it is dispatched; the worker has no request.
- Never update or delete audit entries, and never add a foreign key from `audit_logs` to `users`.

## Authentication and authorization

- Bearer tokens only (Sanctum; `config/sanctum.php` `guard` is empty). Never enable cookie or session auth without an ADR (ADR-003).
- Policies check **permissions** (`App\Enums\Permission`), never role names. Member access is decided by ownership: the user's own `factory_id` / `service_provider_id`. Use `Response::denyAsNotFound()` when the actor may not see a record and `Response::deny()` when they may see it but not act. See `docs/roles-permissions.md`.
- Authorize in the Form Request `authorize()` (it runs before validation) or with `Gate::authorize()`. Add every new ability to the policy matrix tests in `tests/Feature/Policies/`.
- Never put `role`, `factory_id`, `service_provider_id`, `deactivated_at` or `email_verified_at` in `$fillable` or accept them from a member's request.
- Anything that creates a password reset token runs inside a queued job, and emails are sent synchronously from that job, so tokens never sit in queue payloads (ADR-011). Responses on auth endpoints must not reveal whether an account exists, either in content or in timing.
- Demo or known-password accounts are created only in the `local`/`testing` environments, with the guard inside the seeder that creates them. The full demo dataset (`DemoDataSeeder`, ADR-024) is explicit only, create-only and drives every workflow step through the API; never call it from `DatabaseSeeder` or a deployment.
- An email identifies an account only when it equals the stored address apart from letter case. The MySQL collation also ignores accents and some invisible characters, so never rely on the database match alone. Hash any cache or rate-limiter key built from user input (the database cache store limits keys to 255 characters).

## Testing and quality gates

- MySQL is required for tests (`jahez_testing` database). Tests use `LazilyRefreshDatabase`; stray HTTP requests are blocked and `Sleep` is faked globally in `tests/Pest.php`.
- Before finishing, run: `php artisan test --compact`, `composer analyse` (Larastan level 8) and `vendor/bin/pint --dirty --format agent`.
- Concurrency and locking tests must run on MySQL. Never weaken or delete a failing test to make the suite pass.
- For a bug or security fix, write the regression test first and see it fail for the expected reason. Compare JSON columns with `toEqual()`; MySQL 8 does not keep the order of their keys.
- The suite must also pass on MySQL 8.4 (`DB_PORT=3307 php artisan test`, local server on port 3307): always for locking and concurrency work, and before each release.
- Performance scripts live in `tests/performance/` (k6). Never point them at production, and never state capacity or response-time figures without a measurement on staging (`docs/performance/plan.md`).

## Documentation duties

- When the API contract changes, update `docs/api-conventions.md` (if conventions change), the Postman collection in `postman/` and the relevant module docs.
- When a material architecture decision changes, add or update an ADR in `docs/decisions/`.
- Each phase keeps a log in `docs/phases/` with the commands run and their real results.
- Record security review findings in `docs/security/findings.md` (severity, evidence, fix, regression test). A new production setting needs a check in `app:check-production` and an entry in `docs/deployment.md`.
- Never claim that a test, scan, benchmark or skill was run unless it actually was.
- End each task with: changed files, test results, security and performance implications, risks and remaining work.
