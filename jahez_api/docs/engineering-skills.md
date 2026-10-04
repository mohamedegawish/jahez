# Engineering Skills and Tools

This records which coding-agent skills and tools were **actually discovered and used**. Update it each phase. Never list a skill as used unless it was invoked or its files were read for that phase.

Last updated: business-logic completion, 2026-10-03.

## Business-logic completion usage (2026-10-03)

| Skill / tool | How it was used | Outcome |
| --- | --- | --- |
| Manual mutation script | 28 exact-text mutations over the new controls (evaluation scale, review request, directory eligibility, size, adding providers, offer expiry, single award, history, agreements, contracts, invoice issuer, tax rounding, numbering lock, idempotency, callback duplicates and amounts, gateway key) | Results in the [Phase 6 log §9](phases/phase-06-requests-offers-negotiation.md#9-business-logic-completion-2026-10-03) |
| MySQL 8.4.9 | The full suite after each unit (parallel) | Passed after each unit |
| `postman/run-collection.mjs` | The collection extended to 106 requests, run against the seeded dev database | 437 assertions passed, 0 failed |
| Larastan, Pint | After each unit | Clean; Larastan found real type gaps (mixed ids, list shapes), fixed in the code |
| Not run | `code-review` and `finecomb` over this round's commits (the owner asked to focus on business logic, not a security-audit report); `security-review` (no remote); Newman (not installed) | Recommended next step |

## Phases 4–7 usage

| Skill / tool | How it was used | Outcome |
| --- | --- | --- |
| **`code-review`** | **Invoked** (forked) on every uncommitted change of `phase/04-07-marketplace`, focused on authorization between factories and competing providers, negotiation privacy, transitions and locking, audit atomicity, validation, N+1 and invented business rules | **10 findings** (CR-26 to CR-35): 8 fixed test-first, 1 coverage gap closed, 1 accepted as an owner question ([findings.md](security/findings.md#phases-46-code-review-code-review-skill-branch-phase04-07-marketplace-2026-10-03)) |
| Manual mutation script | 46 exact-text mutations over the Phase 4–6 controls (30 after implementation, then 16 more after the review fixes), each running one test file and restoring the source | 46 caught; `app/`, `routes/` and `database/` identical to the backup afterwards |
| PHP `ZipArchive` + SimpleXML | Read the services workbook (`.xlsx`) cell by cell, first to plan the catalog, then inside `ServiceCatalogSourceTest` | The catalog is compared with the workbook itself, not with copied constants |
| MySQL 8.4.9 | The full suite, serial and parallel | 555/555 pass, as on MariaDB 10.4 |
| gitleaks 8.30.1 | `gitleaks dir` over a copy of the 125 changed and new files | No leaks |
| k6 2.2.0 | `smoke.js` against PHP's built-in server | 42/42 checks; correctness only |
| `postman/run-collection.mjs` | The extended collection (79 requests) against the seeded dev database | 327 assertions passed, 0 failed |
| Laravel Boost `boost:update` | Regenerated `AGENTS.md` after the marketplace rules were added to `.ai/guidelines/jahez.md` | Done |
| Not run | `finecomb` over the Phase 4–6 surface (planned for the Phase 9 repeat); `security-review` (no remote); Newman (not installed); `laravel-best-practices` and `testing-best-practices` were not invoked again (the rules read in earlier phases were applied) | Recorded as remaining work in the [Phase 6 log](phases/phase-06-requests-offers-negotiation.md#8-next) |

## Phase 10 preparation usage

| Skill / tool | How it was used | Outcome |
| --- | --- | --- |
| gitleaks 8.30.1 (installed with approval) | `gitleaks git --log-opts="--all" --redact .` | 5 commits, no leaks |
| MySQL 8.4.9 (installed with approval) | The full suite (serial and parallel), the migration cycle, and engine checks (JSON key order, collation, CHECK constraint) | 284/284 pass; CR-14 reproduced; FC-01 confirmed on the target engine |
| k6 2.2.0 (installed with approval) | `smoke.js` and `read-load.js` run locally against PHP's built-in server | Scripts validated; no performance claims ([record](performance/results/2026-10-03-local-script-validation.md)) |
| Laravel Boost `search-docs` | Sanctum routes (the docs do not cover disabling them, so the vendor `SanctumServiceProvider::defineRoutes` was read and showed `sanctum.routes`) | FC-17 fixed with `routes => false` |
| Vendor source reading | `ApplicationBuilder` (the `/up` route has no middleware), Laravel's `server.php` (expects `public/` as working directory), `ServeCommand` (does not pass custom environment variables) | Explained the first k6 failure and how to raise the rate limit locally |

## Phase 8/9 usage

| Skill / tool | How it was used | Outcome |
| --- | --- | --- |
| **`finecomb:finecomb`** | **Invoked** as an authorized audit of this repository. The scope and exclusions are in [security/findings.md](security/findings.md). Loaded: the per-object questions, all root-cause facets, dimensions 9/13/14/23/27/28/30/32/46, specialties 4.5/4.7/4.10/4.14/4.23/4.40/4.58, the PHP and SQL tables, history groups 15–16 and the report format. Every in-scope file was read in full. | **16 findings** (FC-01 to FC-16): 11 fixed with regression tests, 1 mitigated, 4 accepted with reasons. The most serious: login lockout bypass by respelling the email (FC-01, Medium). Coverage gaps are listed in the [Phase 9 log](phases/phase-09-hardening.md#3-finecomb-audit). |
| **`code-review`** | Reviewed the whole branch diff (forked) | **15 findings** (CR-11 to CR-25): 13 fixed with regression tests, 1 process item done, 1 accepted. Each was verified against the code first; CR-11 was reproduced by running the command. |
| `security-review` | Not run: it still needs `origin/HEAD`, and there is no remote ([OQ-28](open-questions.md#oq-28)) | Covered by `finecomb` and the code review |
| `laravel-best-practices`, `testing-best-practices` | No new rule files read this phase. The rules read in Phases 0–3 were applied (Form Requests, policies, transactions, failing test first, datasets, mutation checks). | — |
| Laravel Boost `search-docs` | Route parameter constraints (resource `where`), cursor pagination | APIs confirmed before use |
| Laravel Boost `database-query` | Read-only checks of the dev database: queue payloads (no tokens), audit entries written by the worker | End-to-end evidence in the [Phase 9 log](phases/phase-09-hardening.md#46-live-checks-php-artisan-serve-on-port-8765-dev-database) |
| Vendor source reading | `Cache\DatabaseStore` (`insertOrIgnore`, `incrementOrDecrement`), `Cache\RateLimiter::increment`, `Pagination\Cursor::fromEncoded`, `Routing\PendingResourceRegistration::where`, the hashing defaults | Explained FC-02 (truncated keys never counted) and FC-03 (cursor 500s) before the fixes |
| Read-only probes | A bootstrapped PHP script compared email spellings under the column collation, the `email` rule and the key normalisation; a temporary Pest file measured cursors, routes, sessions, the hash driver and `sectors` cost (deleted after the run) | Found FC-01 (U+FE0F) and FC-06–FC-09 |
| Manual mutation script | 41 exact-text mutations, each running one test file and restoring the source | 41 caught; the sources matched the backup afterwards |
| Not run | `leadlens-laravel:pr-review`, a dedicated secret scanner (not installed), Newman (not installed) | Pattern-based `git grep` secret scan instead; Postman run with the bundled runner |

## Phase 3 usage

| Skill / tool | How it was used | Outcome |
| --- | --- | --- |
| `laravel-best-practices` | Read `rules/validation.md`, `events-notifications.md`, `error-handling.md` (new); re-applied `security.md`, `routing.md`, `eloquent.md`, `migrations.md` | Form Requests with `authorize()` before validation; `safe()->only()`; policies with `denyAsNotFound`; jobs dispatched `afterCommit`; deliberate FKs and a CHECK constraint; explicit `$fillable` |
| `testing-best-practices` | Read `rules/naming.md`, `review.md`, `finding-features.md` (new) | Status codes in test names; `describe()` per controller action; **full permission matrices at the policy level** (`tests/Feature/Policies`); cross-tenant 404 tests; bound datasets; mutation checks |
| **`code-review`** (high, auth focus) | Reviewed the Phase 3 diff | **10 findings; 8 fixed with regression tests, 1 accepted, 1 not fixed by decision.** See the [phase log](phases/phase-03-auth.md#5-code-review-findings). |
| `security-review` | **Attempted; could not run.** It needs `origin/HEAD`, and the repository has no remote ([OQ-28](open-questions.md#oq-28)). No fake remote was added to work around this. | Covered by the high-effort code review plus 11 mutation checks of security controls |
| Laravel Boost `search-docs` | Sanctum token callback, password broker, `ResetPassword::createUrlUsing`, `denyAsNotFound`, `Password::defaults` | APIs confirmed before use |
| Vendor source reading | Sanctum `Guard::isValidAccessToken`, `PasswordBroker` constants, `RefreshDatabase` teardown, `BcryptHasher` limit, Pest `toThrow` | Found bcrypt's silent 72-byte truncation (now validated); confirmed the token-validity callback point |
| Not run | `finecomb`, `leadlens-laravel:pr-review` | Deferred to the Phase 9 full security audit |

## Phase 2 usage

| Skill / tool | How it was used | Outcome |
| --- | --- | --- |
| `laravel-best-practices` | Read `rules/eloquent.md` (new this phase) and re-applied `migrations.md`, `security.md`, `db-performance.md` | Typed relationships with generics; `casts()`; `whereBelongsTo()` in seeders; deliberate `restrictOnDelete` FKs; honest `down()` methods; explicit `$fillable` that excludes unapproved thresholds |
| `testing-best-practices` | Read `rules/test-data.md` and `rules/assertions.md` (new this phase) | Each test seeds its own data; arrange/act/assert; known expected values written in the test; `assertModelExists`; mutation checks |
| **`code-review`** (medium) | Reviewed the uncommitted Phase 2 diff | **No findings.** Checked seeder idempotency, FK/unique alignment, threshold protection, the transaction and the test-user guard. |
| Larastan | Level 8 on the new models and seeders | 1 error (an untyped `factory()->raw()` array in `DatabaseSeeder`), fixed by restructuring rather than casting |
| Vendor source reading | `InteractsWithDatabase::seed`, `SeedCommand` | Found that `$this->seed()` would abort in production. The production-environment test uses `db:seed --force`, as a real deployment does. |
| Not run | `security-review`, `finecomb`, `leadlens-laravel:pr-review` | No new attack surface (no endpoints, seeder-only writes). Deferred to Phase 3. |

## Phase 1 usage

| Skill / tool | How it was used | Outcome |
| --- | --- | --- |
| `laravel-best-practices` | Rule files read in Phase 0 (architecture, routing, security, config, db-performance, migrations) applied to the Phase 1 code | Thin invokable controller; `env()` only in config (also enforced by an arch test); `Context` for the request ID; `Model::shouldBeStrict()`; named rate limiter keyed by user/IP |
| `testing-best-practices` | Rule files read in Phase 0 (endpoint-tests, security, isolation, performance) applied to the 35 tests | Global `Http::preventStrayRequests()` and `Sleep::fake()`; `LazilyRefreshDatabase`; framework fakes created inside tests (`Exceptions::fake()`); exact message assertions; datasets; real DB, no mocks; parallel-safe |
| **`code-review`** (medium) | Reviewed the uncommitted Phase 1 diff against baseline `020f811` | **2 findings, both fixed with regression tests:** (1) guest rate limiting behind a proxy (added env-driven `config/trustedproxy.php`); (2) custom `ValidationException` responses discarded by the renderer (now passed through). See the [phase log](phases/phase-01-foundation.md#5-code-review-findings). |
| Laravel Boost `search-docs` | Laravel 12: exception render callbacks, `throttleApi`, `Context`, `Exceptions::fake`, `config:publish cors`. Pest 3: arch presets, `toBeUsedIn`, global hooks. | APIs confirmed before use |
| Laravel Boost `application-info` | Package versions | — |
| Vendor source reading | `Foundation/Exceptions/Handler::render`/`prepareException`/`convertExceptionToArray`, `ExceptionHandlerFake::render`, `RefreshDatabase::beginDatabaseTransaction`, `TrustProxies::setTrustedProxyIpAddresses`, Pest `ArchPresets` | Found the production 404 model-name leak; diagnosed the test-teardown failure; confirmed the trusted-proxy config fallback |
| Not run | `security-review`, `finecomb`, `leadlens-laravel:pr-review` | Deferred: the code-review pass covered this small diff. A full security review is planned for Phase 3 (auth) and Phase 9. |

## 1. Project-local skills (`.claude/skills/`, installed by Laravel Boost per `boost.json`)

| Skill | Purpose | Phase 0 usage | How it shaped Phase 0 | Planned use |
| --- | --- | --- | --- | --- |
| `laravel-best-practices` | Laravel conventions, with rule files per concern | **Invoked** via the Skill tool. **Read** `rules/architecture.md`, `migrations.md`, `security.md`, `routing.md`, `db-performance.md`, `config.md`. | Thin controllers, Form Requests, policies, action classes only where justified; deliberate FKs and honest rollbacks; indexes from measured queries; `preventLazyLoading`; `lockForUpdate` inside transactions; `env()` only in config; `composer audit`. Applied in [architecture.md](architecture.md) and [data-model.md](data-model.md). | Every PHP change (P1–P11) |
| `testing-best-practices` | Laravel/Pest test design | **Invoked.** **Read** `rules/endpoint-tests.md`, `security.md`, `isolation.md`, `performance.md`. | 404 for cross-tenant access; policy-level permission matrices; one-case-per-rule validation tests; framework fakes; `LazilyRefreshDatabase`; `Http::preventStrayRequests()`; no mocking of the DB. These feed the test plan in [implementation-plan.md](implementation-plan.md). | Every test (P1–P11) |
| `infer-conventions` | Infers codebase conventions into `.ai/rules` | **Read** `SKILL.md` only. **Not run**: it is marked `disable-model-invocation: true` and must run only when the user explicitly asks. The skeleton also has no app code to infer from. | None | Only if the user requests it, after real code exists |
| `deploying-to-cloud` | Laravel Cloud deployment | **Read** the header only. Not used. | None. The deployment target is unknown ([OQ-24](open-questions.md#oq-24)). | P11, only if Laravel Cloud is chosen |
| `tailwindcss-development` | Tailwind UI work | Not used. Not relevant to an API. | None | Not planned |

## 2. Session / plugin skills available in this environment

| Skill | Phase 0 usage | Reason | Planned use |
| --- | --- | --- | --- |
| `code-review` | Not run | It reviews a diff or branch, and the project is not a git repository ([OQ-28](open-questions.md#oq-28)). Phase 0 changed no code. | End of each implementation phase, once git exists |
| `security-review` | Not run | Reviews pending changes on the current branch; same git blocker. | P3, P6, P7, P9 |
| `finecomb:finecomb` | Not run | Exhaustive code/security audit. There is no domain code yet to audit. | P9 security hardening (and P3 auth review) |
| `leadlens-laravel:pr-review` / `leadlens-laravel:pr-reviewer` agent | Not run | Reviews a PR/diff; there is none yet. | Independent review at the end of each phase |
| `simplify` | Not run | No changed code | After implementation phases |
| `anthropic-skills:xlsx` | Not run | The workbook was not supplied ([OQ-01](open-questions.md#oq-01)). | **Read the services workbook as soon as it is supplied** |
| `anthropic-skills:pdf` | Not invoked | The DOC PDF content was supplied inline in the conversation (text plus page images) and read directly. | If further PDF sources arrive |
| `anthropic-skills:docx` | Not invoked | No `.docx` was supplied. | If the original `.docx` is supplied ([OQ-02](open-questions.md#oq-02)) |

## 3. MCP tools

| Tool | Phase 0 usage |
| --- | --- |
| Laravel Boost `application-info` | **Used.** Confirmed PHP 8.2, Laravel 12.69.3, default DB engine sqlite, and installed package versions. |
| Laravel Boost `database-connections` | **Used.** Default `sqlite`; configured: sqlite, mysql, mariadb, pgsql, sqlsrv. |
| Laravel Boost `search-docs` | **Used.** Laravel 12: `install:api` installs Sanctum and creates `routes/api.php`; `shouldRenderJsonWhen`; the `/up` health route and `DiagnosingHealth` event; Sanctum tokens never expire by default, plus `expiration` and `sanctum:prune-expired`. |
| Laravel Boost `database-schema` / `database-query` | Not used. The schema was read from migration files, and `migrate:status` was used instead. |

## 4. Unavailable or missing

- **No dedicated performance or load-testing skill** is available in this environment.
- **Locust, Newman and Docker are not installed.** k6 was installed for Phase 10 on 2026-10-03, with approval.
- ~~No static-analysis tool~~: Larastan added in Phase 1 ([ADR-005](decisions/ADR-005-static-analysis.md)).
- ~~No secret-scanning tool~~: gitleaks installed 2026-10-03, with approval.
- **No OpenAPI generator** is installed. Endpoint docs currently live in `docs/api-conventions.md` and Postman. A choice is needed before Phase 3 endpoints ship.
- ~~No git~~: initialised in Phase 1, so diff-based review skills now work ([ADR-001](decisions/ADR-001-version-control.md)).
