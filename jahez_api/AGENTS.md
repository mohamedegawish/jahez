<laravel-boost-guidelines>
=== .ai/jahez rules ===

# Jahez API: Project Rules

Jahez is a JSON API for the Industrial Modernisation Centre (IMC) "Smart Industry – Ecosystem" initiative. It connects IMC staff, industrial factories and digital-transformation service providers.

## Read first

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
- A provider reaches factories only after IMC approval. Eligibility for a request: approved, offers the service through a listing IMC approved (ADR-021), and targets one of the factory's sectors (`ServiceProvider::eligibleFor()` and `offering()`, which uses `approvedServices`). Approval fields are never fillable; they change only through `POST /service-providers/{id}/approval`, listing statuses only through `POST /service-providers/{id}/services/{catalogService}/review`, factory approval only through `POST /factories/{id}/approval` (never touching the readiness classification).
- Marketplace statuses change only through the action endpoints and `ServiceRequest::moveTo()` / `ProviderRequest::moveTo()` (the enums' `canBecome()`); clients never send a status. The states are PROPOSED (`docs/workflows.md`, OQ-38): update the workflow doc and ADR-015 with any new state or transition.
- Lock order: the `service_requests` row first, then its `provider_requests` rows (`ProviderRequest::lockWithServiceRequest()`, `closeOpenThreadsOf()`). Messages and offers lock only their thread (`lockThread()`). Call `ensureProviderApproved()` before any step that moves a thread forward.
- A provider sees only its own thread; competitors get 404. IMC administrators see statuses, never messages or offers (PROPOSED, OQ-39). Never write message text, offer terms or prices to the audit log.
- Offers are append-only versions checked with `based_on_version`. Prices are `DECIMAL(14,2)` decimal strings, EGP only, and informational. An agreed offer is not a contract, invoice or payment: add no contract, invoice, payment, fee or revenue-share tables or fields until OQ-15, OQ-16 and OQ-17 are answered (Phase 7 is BLOCKED).
- Financial and contract rules (issuer, numbering, taxes and fees, payment terms, revenue share, contract templates) are approved, versioned database policies (ADR-023), never config, env or frontend constants. Resolve them with `AppBillingPolicyResolver` inside the transaction that makes the record (share-locked), store the version ids and computed values on the record, and never recalculate it. Never seed a policy or a default value; tests create fixtures with `configureBilling()` / `approvedPolicyVersion()`. Change policy state only through `AppBillingFinancialPolicyLifecycle`; whoever prepared a version never approves it.
- Factories are classified only by the digital readiness assessment (ADR-018, source `docs/إطار تقييم مستوى الجاهزية الرقمية.docx`): the server sums the stored choice points (10–40) and picks the category of the version answered (B4 Automation 10–17, Basic 18–25, Advanced 26–33, Smart 34–40). Never accept a score, category or points from a client, and never add weights, normalisation or categories the source does not state. Assessments and answers are append-only; an answered questionnaire version is never changed in place (publish a new version). Manual classification (ADR-016) is retired and read-only. The readiness categories are separate from the DOC maturity tiers (OQ-41), whose score columns stay NULL.
- Readiness recommendations of version 1 map only to existing catalog services with the same text (`ReadinessAssessmentSourceTest`); a line the catalog lacks stays unmapped (OQ-42). In later versions IMC maps lines to existing catalog services only; no catalog service is ever created for a recommendation. `filter[recommended]` narrows eligible providers and never replaces `ServiceProvider::eligibleFor()`.
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

=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.2. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- This project uses the streamlined Laravel 11+ structure: register middleware, exceptions, and routing in `bootstrap/app.php` and service providers in `bootstrap/providers.php`. There is no `app/Http/Kernel.php` or `app/Console/Kernel.php`, and commands in `app/Console/Commands/` auto-register.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.

- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>
