# Phase 1: Project Foundation and Quality Gates

**Date:** 2026-10-02 · **Branch:** `phase/01-foundation` (from `main` @ `020f811`) · **Result:** exit gate **PASS** (§7)

## 1. Scope

The platform foundation only. **No business endpoints and no source requirements are implemented in this phase.**

Owner instructions: "use mysql and start next phase". This was taken as approval of A1–A4 from the Phase 0 report and as the answer to A5 ([implementation-plan.md §2](../implementation-plan.md#2-approvals)).

| Delivered | Where |
| --- | --- |
| git repository and baseline commit | `main` @ `020f811` ([ADR-001](../decisions/ADR-001-version-control.md)) |
| MySQL for development and tests | `.env`, `.env.example`, `phpunit.xml` ([ADR-004](../decisions/ADR-004-database-mysql.md)) |
| `/api/v1` routing + Sanctum installed; unversioned scaffold `GET /api/user` removed | `routes/api.php`, `bootstrap/app.php` ([ADR-002](../decisions/ADR-002-api-versioning-and-sanctum.md)) |
| JSON error envelope for all `api/*` exceptions | `app/Http/Responses/ApiExceptionRenderer.php` ([ADR-009](../decisions/ADR-009-error-envelope-and-request-id.md)) |
| Request-ID correlation (header + log `Context`) | `app/Http/Middleware/AssignRequestId.php` |
| Readiness endpoint `GET /api/v1/health` (not rate limited) | `app/Http/Controllers/Api/V1/HealthController.php` |
| Rate limiting (`api` limiter, per user or guest IP) and trusted-proxy configuration | `AppServiceProvider`, `config/api.php`, `config/trustedproxy.php` |
| CORS deny-by-default allow-list | `config/cors.php` |
| `Model::shouldBeStrict()` outside production | `AppServiceProvider` |
| Test harness: MySQL, `LazilyRefreshDatabase`, global stray-HTTP block and `Sleep` fake | `tests/Pest.php`, `phpunit.xml` |
| Larastan level 8 (`composer analyse`) | `phpstan.neon`, `composer.json` ([ADR-005](../decisions/ADR-005-static-analysis.md)) |
| AI agent rules merged into the generated `AGENTS.md` | `.ai/guidelines/jahez.md` ([ADR-007](../decisions/ADR-007-agent-guidelines.md)) |
| Docs: README, docs index, API conventions, testing strategy, troubleshooting, 6 ADRs; updates to the plan, architecture, data model, open questions and skills | `README.md`, `docs/` |
| Postman collection + environments + README | `postman/` |

**Not delivered:**
- **CI.** There is no remote yet ([OQ-28](../open-questions.md#oq-28)).
- **A Newman run.** Newman is not installed. An equivalent `curl` smoke test was run instead (§4.6).

## 2. Decisions

| Decision | Reason | Record |
| --- | --- | --- |
| Tests run on MySQL (`jahez_testing`), not SQLite | Owner chose MySQL; SQLite cannot check row locks or MySQL constraints | ADR-004 |
| The local server is MariaDB 10.4 behind the `mysql` driver | It is the only MySQL-protocol server on the machine; documented as a known deviation | ADR-004 |
| Error rendering through a `render()` callback (not only `shouldRenderJsonWhen`) | Laravel's default production JSON leaks `No query results for model [App\Models\…]` on 404 | ADR-009 |
| Readiness is a separate endpoint rather than a `DiagnosingHealth` listener on `/up` | A database outage should not fail the liveness probe and trigger restarts | [architecture.md §4](../architecture.md#4-cross-cutting-concerns) |
| `shouldBeStrict()` instead of only `preventLazyLoading()` | Also catches silently discarded mass-assignment attributes in development and tests | architecture.md §4 |
| Client `X-Request-Id` accepted only if it matches `[A-Za-z0-9._-]{8,128}` | End-to-end correlation without log injection | ADR-009 |
| `config/filesystems.php` and `config/sanctum.php` excluded from Larastan | Unmodified upstream templates; the reported `env()` typing is harmless | ADR-005 |
| `HasApiTokens` **not** added to `User`; Sanctum token expiry **not** set | Belongs to the authentication-mode decision (ADR-003, [OQ-22](../open-questions.md#oq-22)) | ADR-002 |
| `APP_NAME=Jahez` in `.env.example` | Internal name used by the brief. Public branding is still [OQ-29](../open-questions.md#oq-29). | — |

## 3. Changed files

**New (33):**
- `.ai/guidelines/jahez.md`
- `app/Http/Controllers/Api/V1/HealthController.php`, `app/Http/Middleware/AssignRequestId.php`, `app/Http/Responses/ApiExceptionRenderer.php`
- `config/api.php`, `config/cors.php` (published, then edited), `config/sanctum.php` (published), `config/trustedproxy.php`
- `database/migrations/2026_10_02_172319_create_personal_access_tokens_table.php` (Sanctum)
- `routes/api.php`, `phpstan.neon`
- `tests/Feature/Api/{ApiVersioningTest,CorsTest,ErrorResponseTest,RateLimitTest,RequestIdTest}.php`, `tests/Feature/Api/V1/HealthTest.php`, `tests/Unit/ArchitectureTest.php`
- `docs/README.md`, `docs/api-conventions.md`, `docs/testing/strategy.md`, `docs/troubleshooting.md`, `docs/decisions/ADR-00{1,2,4,5,7}-*.md`, `docs/decisions/ADR-009-*.md`, this file
- `postman/` (4 files)

**Modified (15):**
- `docs/phases/phase-00-discovery.md` (2 anchor links updated after the headings they point to were renamed)
- `.env.example`, `AGENTS.md` (regenerated by Boost), `README.md`
- `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`
- `composer.json`, `composer.lock`
- `phpunit.xml`, `tests/Pest.php`
- `docs/architecture.md`, `docs/data-model.md`, `docs/engineering-skills.md`, `docs/implementation-plan.md`, `docs/open-questions.md`

**Outside git:**
- Local `.env`: switched to MySQL; added `CORS_ALLOWED_ORIGINS`, `API_RATE_LIMIT_PER_MINUTE` and `TRUSTED_PROXIES`. The original is backed up in the session scratchpad.
- MySQL databases created: `jahez` and `jahez_testing`, plus `jahez_testing_test_1..4` from the parallel runs.
- A scratch database `jahez_migration_check` was created and dropped.

## 4. Test and check results

### 4.1 Test suite (final)

| Command | Result |
| --- | --- |
| `php artisan test --compact` | **35 passed** (91 assertions), 0 failed, 0 skipped · 2.95 s |
| `php artisan test --parallel --processes=4` | **35 passed** (91 assertions) · 6.30 s |

| File | Tests | Covers |
| --- | --- | --- |
| `Unit/ArchitectureTest` | 3 | Pest `php` and `security` presets; `env()` not used in `App` |
| `Unit/ExampleTest` (skeleton) | 1 | — |
| `Feature/Api/ApiVersioningTest` | 1 | Every `api/*` route is under `api/v1/` |
| `Feature/Api/CorsTest` | 3 | No origins allowed by default; only configured origins; `X-Request-Id` exposed |
| `Feature/Api/ErrorResponseTest` | 11 | 404 (unknown route, no Accept header); 404 with no model name; 405 + Allow; 422 errors; custom validation response kept; 401; 403 message; 409 message; 500 hides details when debug is off; 500 debug details when debug is on; web 404 stays HTML |
| `Feature/Api/RateLimitTest` | 4 | 429 envelope + Retry-After; per-IP buckets; per-client behind a trusted proxy; forwarded headers from untrusted callers ignored |
| `Feature/Api/RequestIdTest` | 8 | UUID generated; valid ID echoed; 4 malformed variants replaced; shared via `Context`; web responses |
| `Feature/Api/V1/HealthTest` | 3 | 200 ok; 503 with no details when the DB is unreachable (and the failure is reported); not rate limited |
| `Feature/ExampleTest` (skeleton) | 1 | `GET /` → 200 |

### 4.2 Test history during the phase (failures were not hidden)

1. First run: **31 passed, 1 failed.** The "DB unreachable" health test threw `PDOException: Unknown database` in the `RefreshDatabase` teardown, because the test had purged the default connection. Fixed by using a separate `unreachable` connection ([troubleshooting.md](../troubleshooting.md)). Then 32/32.
2. **Mutation check:** with the renderer unregistered in `bootstrap/app.php`, 9 of 10 `ErrorResponseTest` cases failed as expected. The web-route case correctly passed. The file was restored byte-for-byte.
3. After the code-review fixes (§5): 35/35. A second mutation check (removing the custom-validation-response pass-through) made the new test fail as expected, and the file was restored.

### 4.3 Static analysis and formatting

| Command | Result |
| --- | --- |
| `composer analyse` (Larastan 3.12.2 / PHPStan 2.2.16, level 8) | First run: **2 errors**, both in unmodified upstream `config/filesystems.php:44` and `config/sanctum.php:21` (`env()` typed `bool|string`). Excluded with a documented reason (ADR-005). Final: **0 errors, 28 files**. |
| `vendor/bin/pint --dirty --format agent` | First run fixed `tests/Feature/Api/CorsTest.php` (imports). Then `passed`. |
| `vendor/bin/pint --test` | `passed` (read-only check of the whole project) |

### 4.4 Migrations

| Check | Result |
| --- | --- |
| Dev DB `jahez`: `php artisan migrate` | 3 skeleton migrations (batch 1), then Sanctum `personal_access_tokens` (batch 2): all Ran |
| Scratch MySQL DB `jahez_migration_check`: migrate → rollback → migrate → fresh | All exit 0. Rollback left only `migrations`. Indexes on `personal_access_tokens`: PK, UNIQUE token, (tokenable_type, tokenable_id), expires_at. DB dropped afterwards. |

### 4.5 New-developer setup check (exit-gate evidence)

The project's tracked and non-ignored files were copied into an empty scratch directory (no `vendor/`, no `.env`), and the README "Local setup" steps were followed:
- `composer install` → OK
- `cp .env.example .env` + `php artisan key:generate` → OK
- Create databases → OK
- `php artisan migrate` → OK
- Tests → 32/32 at that point; **35/35** after re-syncing the final files
- `composer analyse` → 0 errors
- `pint --test` → passed

### 4.6 Live smoke test (instead of Newman)

`php artisan serve --port=8765`, then `curl`. Every response matched the Postman assertions:

| Request | Response |
| --- | --- |
| `GET /api/v1/health` | 200 `{"data":{"status":"ok","checks":{"database":"ok"}}}` + UUID `X-Request-Id` |
| `GET /api/v1/nope` | 404 `{"message":"Resource not found.","code":"not_found","request_id":"<same as header>"}` |
| `POST /api/v1/health` | 405, `Allow: GET, HEAD`, `code: method_not_allowed` |
| `X-Request-Id: postman-smoke-0001` | Echoed |
| `X-Request-Id: bad id` | Replaced by a UUID |

Postman JSON files were validated with Node `JSON.parse`, and a secret pattern scan of `postman/` found nothing.

### 4.7 Other

- `composer audit`: "No security vulnerability advisories found." (after adding Sanctum and Larastan)
- `php artisan route:list --except-vendor`: `GET /`, `GET api/v1/health`

## 5. Code review findings

The `code-review` skill (medium) reviewed the Phase 1 diff against `020f811`.

| # | Finding | Severity | Fix | Regression test |
| --- | --- | --- | --- | --- |
| CR-1 | Guests are rate-limited by IP, but no trusted proxies are configured. Behind a load balancer, every guest would share the proxy's IP, so all guests get 429 once 60 requests per minute is reached. | Medium (deployment-dependent) | `config/trustedproxy.php` reads `TRUSTED_PROXIES` (default: trust none). Laravel's `TrustProxies` reads it at request time. | `RateLimitTest`: "limits each client behind a trusted proxy separately"; "ignores forwarded addresses from untrusted callers" |
| CR-2 | The renderer rebuilt every `ValidationException`, discarding a custom response attached to it (Laravel's handler would honour it). | Low | Pass-through when `$exception->response !== null` | `ErrorResponseTest`: "keeps a custom response attached to a validation exception" |

## 6. Security and performance notes

- **Fixed:** the 404 model-class leak; the CORS `*` default; stack traces in API errors when debug is off; forwarded-IP spoofing to evade limits (proxies untrusted by default).
- **Open:**
  - Sanctum tokens never expire (set in Phase 3, RK-13).
  - No authentication or authorization exists yet.
  - `APP_DEBUG=true` in the local `.env` is expected locally, but production must use `false`. The production checklist arrives in Phase 11.
- **Performance:** nothing was measured. The rate limit of 60/min is a provisional default, not a capacity figure. Benchmarks on this Windows/MariaDB host would not represent production (ADR-004).

## 7. Exit gate

| Criterion (master prompt, Phase 1) | Status | Evidence |
| --- | --- | --- |
| Conventions, config, API versioning, safe error handling, logging, test setup | ✅ | §1, §2 |
| Formatter, static analysis and test commands configured | ✅ | `vendor/bin/pint`, `composer analyse`, `php artisan test` |
| CI checks where supported | ⚠️ Not configured | No remote ([OQ-28](../open-questions.md#oq-28)). Commands are documented for CI later. |
| Factories/seeders with synthetic data only | ✅ (unchanged) | Only the skeleton `UserFactory`/`DatabaseSeeder`. No domain data exists yet. |
| Local setup and required services documented | ✅ | `README.md` |
| Tests: boot/health smoke, error serialization, validation/error behaviour, safe production config | ✅ | §4.1 (Health, ErrorResponse incl. debug-off) |
| Formatter, static analysis and existing suite run | ✅ | §4.3, §4.1 |
| **A new developer can set up from the docs and pass the base quality checks** | ✅ | §4.5 |

**Gate: PASS.** CI is the only unmet "where supported" item, blocked on a repository remote.

## 8. Unresolved issues and next phase

- **Commit:** Phase 1 changes are **uncommitted** on `phase/01-foundation`, ready for review with `git diff main`. They will be committed when the owner asks.
- **Phase 2 (data model)** can start on the DOC reference data only, after approval **A6** and an answer to [OQ-23](../open-questions.md#oq-23) (bilingual names), approval **A7**. The catalog stays blocked on the workbook ([OQ-01](../open-questions.md#oq-01)). Engagement, contract and finance tables stay blocked on [OQ-03](../open-questions.md#oq-03) and [OQ-15](../open-questions.md#oq-15)–[OQ-17](../open-questions.md#oq-17).
- Before Phase 6/10: install MySQL 8.4 to match production (RK-08).
