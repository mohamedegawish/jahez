# Phase 9 (first pass): Security Hardening and Audit

**Date:** 2026-10-02 to 2026-10-03 · **Branch:** `phase/08-09-audit-and-hardening` (from `phase/03-auth` @ `4446cba`, shared with the [Phase 8 audit-log slice](phase-08-audit-log.md)) · **Result:** first-pass gate **PASS for the current surface**, with three recorded gaps (§9)

## 1. Scope

Owner instruction: "ok complete" (continue with the audit log and hardening while Phases 4–7 wait for input). This pass covers everything built so far (Phases 1–3 and the audit slice). **Phase 9 will run again** once Phases 4–7 add their surface (uploads, workflows, money).

**Delivered:**
- **Security headers** (`App\Http\Middleware\SecurityHeaders`, outermost global middleware):
  - every response: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`;
  - API responses: also `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'` and `Cache-Control: no-store, private`;
  - HSTS only on HTTPS requests.
- **No storage route:** the local disk has `serve => false`, so `GET /storage/{path}` does not exist (it returns 404, including for `/storage/../../.env`).
- **Go-live gate:** `php artisan app:check-production` with 11 FAIL checks and 3 WARN checks ([deployment.md](../deployment.md#1-go-live-gate)).
- **Larastan has no exclusions:** `config/filesystems.php` was edited (`serve`, string cast), so per ADR-005 it is now analysed.
- **Fixes from the `finecomb` audit and the code review:** 16 + 15 findings, registered in [security/findings.md](../security/findings.md). The most important:
  - FC-01: the login lockout could be bypassed by respelling the email;
  - CR-11: the go-live gate crashed with the shipped `.env`;
  - CR-12: risk of a deadlock between audit writes and administrator locks.
- **Docs:**
  - new: [deployment.md](../deployment.md), [security/findings.md](../security/findings.md), ADR-012, this log and the Phase 8 log;
  - updated: §7.
- **Postman:** folder 12, plus new cases in folders 13 and 14; a collection-level security-header assertion; the runner fixed (FC-13).

## 2. Route-by-route review

`php artisan route:list -v`, read against the code:
- Every `/api/v1` route except `health`, `auth/login`, `auth/forgot-password` and `auth/reset-password` has `auth:sanctum`. The three auth routes have their own limiters.
- `throttle:api` applies to everything except `health`.
- Record routes take a numeric ID only (FC-07, enforced by a test for every API route parameter).
- `Laravel\Mcp\...\AddWwwAuthenticateHeader` sits in the global stack; it comes from a dev dependency and is gone with `composer install --no-dev`.
- Boost routes register only in local or debug mode.
- `GET /` (welcome page) and `GET /up` remain ([OQ-34](../open-questions.md#oq-34)).

## 3. `finecomb` audit

- **Invocation:** "Authorized security audit of the user's own Laravel 12 API", with the scope and exclusions listed in [findings.md](../security/findings.md#phase-9-finecomb-audit-2026-10-03).
- **References loaded:**
  - the per-object question lists;
  - all root-cause facets;
  - dimensions 9, 13, 14, 23, 27, 28, 30, 32 and 46;
  - specialties 4.5, 4.7, 4.10, 4.14, 4.23, 4.40 and 4.58;
  - the PHP and SQL language tables;
  - history groups 15 and 16;
  - the report format.
- **Method:**
  1. The current source of every in-scope file was read in full.
  2. Each entry point (19 API routes), job (2), command (2) and shared state was questioned against those references.
  3. Suspicions were checked against the framework source (`DatabaseStore`, `RateLimiter`, `Cursor`, `PendingResourceRegistration`) and with read-only probes.
  4. Each confirmed defect got a failing test first, then the fix, then a mutation check (§4.3).
- **Probes (all read-only or inside test transactions):**
  - MySQL collation equality of 10 email spellings, the `email` rule and the throttle-key normalisation. U+FE0F is ignored by the collation, accepted by the rule and kept by the key, which gave FC-01.
  - Four crafted cursors returned 500 (FC-03).
  - `/users/2abc` returned user 2 (FC-07).
  - Two `GET /` requests added a session row (FC-09).
  - `HASH_DRIVER=argon2id` made an unknown-email login return 500 (FC-06).
  - `sectors` arrays of 1,000 and 4,000 entries took 0.17 s and 0.91 s (FC-08).
- **Coverage gaps (checks not done, and what each would need):**

| Check | Status | Needs |
| --- | --- | --- |
| Dedicated secret scanner (gitleaks, trufflehog) | Not done: not installed; installing needs approval | Approval to install. A pattern-based `git grep` scan was run instead (§4.5). |
| `security-review` skill | Not runnable: it needs `origin/HEAD` and there is no remote | A git remote ([OQ-28](../open-questions.md#oq-28)) |
| Dynamic testing against a deployed instance (DAST, fuzzing) | Not done: no staging environment | Staging ([OQ-24](../open-questions.md#oq-24)) |
| Behaviour on MySQL 8 | Not run: only MariaDB 10.4 is available. CR-14 (JSON key order) was fixed from documentation, not reproduced. | MySQL 8.4 locally or in CI (RK-08) |

## 4. Tests and checks

### 4.1 Test suite (final)

| Command | Result |
| --- | --- |
| `php artisan test --compact` | **283 passed** (821 assertions), 0 failed |
| `php artisan test --parallel --processes=4 --compact` | **283 passed** (821 assertions) |

**90 tests added since Phase 3** (193 → 283), counted with `vendor/bin/pest --list-tests`:

| File | Tests | Covers |
| --- | --- | --- |
| `Api/V1/AuditLogTest` (new) | 23 | Listing with actor and subject; filters; UTC day boundaries; cursor paging; 6 forged cursors → 422; 9 allow-list cases → 422; 403 members; 401 |
| `Audit/AuditTrailTest` (new) | 21 | Every recorded event and its metadata; nothing recorded for refused or no-op changes; atomicity (login, logout, reset); no secrets stored |
| `Console/CheckProductionConfigurationTest` (new) | 15 | Passes when ready; 10 unsafe settings and a non-production environment each fail; string `BCRYPT_ROUNDS`; WARN-only settings, including `*` proxies |
| `Api/SecurityHeadersTest` (new) | 6 | API headers on success and error responses; baseline headers without the API policy on web pages; HSTS only over HTTPS; no storage route (GET and PUT) |
| `Models/AuditLogTest` (new) | 5 | IP and request ID; secret keys dropped; update/delete refused; no lock wait on the actor's row (second connection) |
| `Api/V1/PaginationLinksTest` (new) | 4 | Page size and filters kept in next-page links (3 offset lists + the audit log) |
| `Policies/AuditLogPolicyTest` (new) | 3 | Audit permission matrix |
| `Auth/LoginTest` | +4 | Any letter case; respelled email stays locked (FC-01); long email with the database cache store, per address and per account (FC-02) |
| `ErrorResponseTest` | +3 | Trailing-character IDs → 404 (users, factories, providers) |
| `PasswordResetTest`, `FactoryTest`, `ServiceProviderTest`, `ApiVersioningTest`, `CreateAdminUserTest`, `Jobs/SendPasswordResetLinkTest` | +1 each | Requester IP passed to the job; sector list bound (×2); every API route parameter has a format; admin creation is atomic; reset retry after a mail failure |

### 4.2 Failures during the phase (not hidden)

1. Every regression test was run **before** its fix and failed for the expected reason. For example: 200 instead of 422 for the respelled login; 500 instead of 422 for the cursors; 3 audit rows instead of 1 for throttled logins; and an `InvalidArgumentException` from the go-live gate.
2. **`subject_id` rule:** `sometimes` stopped `required_with` from running (a real bug, found by the first test). Fixed by dropping `sometimes`. The same trap applied to `subject_type`.
3. **`after_or_equal:from`** failed validation when `from` was absent. The rule is now applied only when `from` is filled.
4. **`Artisan::output()` empties its buffer on read.** Tests now capture it once.
5. **Pest datasets typed `Closure`** are passed through uncalled. The wrapper closures were removed.
6. **The documented Postman runner command failed** inside the repository (FC-13); fixed.

### 4.3 Mutation checks

Each control was disabled by an exact text replacement, the named test file was run, and the original file was restored. **41 mutations, 41 caught.** All sources were byte-identical to a backup afterwards (`diff -r`).

| Group | Controls disabled (each caught by its test file) |
| --- | --- |
| Headers and storage | CSP; `nosniff`; HSTS on plain HTTP; storage route served |
| Audit model | `token` missing from the sanitizer; updates allowed; deletes allowed |
| Audit API | `subject_type`/`subject_id` pairing (×2); cursor check; cursor direction check; permission check; next-page links (×4: factories, providers, users, audit) |
| Audit writes | Login, logout and reset atomicity (×3); throttled-login deduplication; email stored transliterated; job IP not recorded; IP not passed; sector no-op audited (×2); actor foreign key restored |
| Login | Exact-match check; case-insensitive match; address key unhashed; account key unhashed |
| Jobs and commands | Unsent reset token kept on failure; admin creation not atomic |
| Go-live gate | Hash driver ignored; session driver ignored; `BCRYPT_ROUNDS` parsing; wildcard proxies |
| Validation and routes | Sector list unbounded; element rules run on long lists; ID constraints (×3: users, factories, providers) |

The first round left 2 survivors: the cursor direction check and the account-key hashing. Tests were added for both (a non-boolean direction; 20 failures from many addresses), and both are now caught.

### 4.4 Static analysis and formatting

| Command | Result |
| --- | --- |
| `composer analyse` (level 8, no exclusions) | **0 errors, 102 files** |
| `vendor/bin/pint --dirty --format agent` | `passed` |

### 4.5 Dependency audits and secret scan

| Check | Result |
| --- | --- |
| `composer audit` | No security vulnerability advisories |
| `npm audit` | 0 vulnerabilities |
| Pattern scan: `git grep -I --untracked` for AWS keys, private keys, Slack/GitHub/Google/Stripe tokens, a filled `APP_KEY`, Sanctum plaintext tokens, and quoted `password`/`secret`/`api_key`/`token` assignments of 12+ characters | 1 match, a false positive: a test helper's default fake password (`PasswordResetTest.php:15`). `.claude/` was excluded (skill files with placeholder examples). `.env` is not tracked. |

### 4.6 Live checks (`php artisan serve` on port 8765, dev database)

- **Headers:** `GET /api/v1/health` returned `Cache-Control: no-store, private`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, the CSP and `X-Request-Id`. `/storage/x` and `/storage/../../.env` returned 404.
- **Postman:** `node postman/run-collection.js …` ran **39 requests: 160 assertions passed, 0 failed**. Newman was not run (not installed).
- **Queue:** the queue drained with `queue:work --stop-when-empty`: 5 jobs, **0 failed**. The new job payload held the email and the IP, and no token. The audit entry written by the worker had the client IP and the original request ID.
- **Migration:** on the dev database `audit_logs` was rolled back and re-migrated after the foreign key was removed. It had never been deployed anywhere else.

## 5. Code review

The `code-review` skill reviewed the whole branch diff and reported 15 findings:
- 13 fixed with regression tests;
- 1 process item done (the documentation);
- 1 accepted by decision (CR-24, the fixed timing hash; see the reason in [findings.md](../security/findings.md#phase-9-code-review-code-review-skill-branch-diff-2026-10-03)).

Every finding was checked against the code before it was acted on. CR-11 was reproduced by running the command with the local `.env`.

## 6. Security and performance notes

- **Threat model:** T17 (audit) and T18 (headers) are now implemented. Three threats were added ([threat-model.md](../security/threat-model.md)): T19 (identity confusion through collation), T20 (audit tampering or flooding) and T21 (unsafe production configuration).
- **Residual risks:**
  - audit immutability is enforced by the application only (FC-12);
  - unbounded audit growth until retention is decided ([OQ-25](../open-questions.md#oq-25));
  - account lockout as denial of service (FC-14);
  - the welcome page writes sessions unless `SESSION_DRIVER=array` (FC-09).
- **Performance:**
  - Login runs the same queries as before. The throttle keys are hashed (CPU only), and a refused throttled login does one cache lookup to deduplicate its audit entry.
  - Factory and provider writes with `sectors` add one `COUNT` on `sectors` (4 rows).
  - Audit writes add one insert per audited action.
  - Nothing was measured (Phase 10).

## 7. Changed files

**New (25):**
- Code (10): `app/Http/Middleware/SecurityHeaders.php`, `app/Console/Commands/CheckProductionConfiguration.php`, `app/Http/Requests/Api/V1/Concerns/ValidatesSectorCodes.php`, plus the 7 audit-slice files listed in the [Phase 8 log](phase-08-audit-log.md#3-changed-files-audit-slice)
- Tests (7): `tests/Feature/Api/SecurityHeadersTest.php`, `tests/Feature/Console/CheckProductionConfigurationTest.php`, `tests/Feature/Api/V1/PaginationLinksTest.php`, plus the 4 audit-slice tests
- Docs (5): `docs/deployment.md`, `docs/security/findings.md`, `docs/decisions/ADR-012-audit-log.md`, `docs/phases/phase-0{8,9}-*.md`
- Migration (1): `audit_logs`

**Modified:**
- Code: the 9 files listed in the Phase 8 log, plus `app/Http/Requests/Api/V1/{Store,Update}{Factory,ServiceProvider}Request.php`, `app/Models/AuditLog.php`, `bootstrap/app.php`, `config/filesystems.php`, `phpstan.neon` and `routes/api.php`
- Tests: `tests/Feature/Api/{ApiVersioning,ErrorResponse}Test.php`, `tests/Feature/Api/V1/{Factory,ServiceProvider}Test.php`, `tests/Feature/Api/V1/Auth/{Login,PasswordReset}Test.php`, `tests/Feature/Console/CreateAdminUserTest.php`, `tests/Feature/Jobs/SendPasswordResetLinkTest.php`
- Postman: `Jahez-API.postman_collection.json`, `run-collection.js`, `README.md`
- Docs:
  - `README.md`
  - `docs/{README,api-conventions,api-endpoints,architecture,data-model,engineering-skills,implementation-plan,open-questions,requirements-traceability,roles-permissions,troubleshooting}.md`
  - `docs/security/{threat-model,security-test-matrix}.md`
  - `docs/testing/strategy.md`
  - `docs/decisions/ADR-005-static-analysis.md`
  - `.ai/guidelines/jahez.md`
  - `AGENTS.md` (regenerated)

**Outside git:**
- Dev database `jahez`: `audit_logs` re-migrated, Postman records added, the queue drained.
- Local mail log: reset and invitation emails from the queue run.

## 8. Open questions affected

- [OQ-25](../open-questions.md#oq-25): extended (audit retention, failed-login emails, database-level immutability).
- New [OQ-34](../open-questions.md#oq-34): is the project API-only, so that the welcome page and front-end scaffolding can go?

## 9. Exit gate (first pass)

| Criterion (master prompt, Phase 9) | Status | Evidence |
| --- | --- | --- |
| Route-by-route review | ✅ | §2 |
| `composer audit` / `npm audit` | ✅ | §4.5 |
| Secret scan | ⚠️ pattern-based only | §4.5; a dedicated scanner needs approval |
| Security headers | ✅ | `SecurityHeadersTest`, live check §4.6 |
| CORS/CSRF matching the client model | ✅ (unchanged since Phase 3) | `CorsTest`; bearer-only, so CSRF does not apply |
| Threat model and security test matrix updated | ✅ | `docs/security/` |
| `finecomb` and `security-review` | ✅ `finecomb`; ⚠️ `security-review` not runnable | §3 |
| Every finding becomes a regression test | ✅ | [findings.md](../security/findings.md); 41/41 mutations caught |

**Gate: PASS for the current surface.** Three gaps are recorded: no dedicated secret scanner, `security-review` not runnable, no DAST. Phase 9 runs again after Phases 4–7.

## 10. Next

- Phases 4–7 remain blocked on owner input ([OQ-01](../open-questions.md#oq-01), [OQ-03](../open-questions.md#oq-03), [OQ-06](../open-questions.md#oq-06), [OQ-07](../open-questions.md#oq-07), [OQ-13](../open-questions.md#oq-13)–[OQ-16](../open-questions.md#oq-16)).
- Possible without input: Phase 10 preparation, which needs approval to install k6 or Locust and a Linux staging host.
- **Commit:** these changes are uncommitted on `phase/08-09-audit-and-hardening`. Review them with `git diff 4446cba`.
- **Committed** on 2026-10-03 as `368ca11`.

## 11. Follow-up (2026-10-03)

After the commit, the owner approved installing gitleaks, k6 and MySQL 8.4, and answered OQ-34 (API-only). The work is on branch `phase/10-tooling-and-api-only`; the installs are described in the [Phase 10 log](phase-10-performance-prep.md).

| Item | Result |
| --- | --- |
| **Dedicated secret scan** | `gitleaks git --log-opts="--all" --redact .` (gitleaks 8.30.1): **5 commits scanned (~1.49 MB), no leaks found**. Closes the scanner gap. |
| **Test suite on MySQL 8.4.9** | `DB_PORT=3307 php artisan test --compact`: 283 passed (821 assertions), the same as MariaDB 10.4; parallel 283 passed. Confirmed against the server: the tests had created their 22 tables in the 8.4 `jahez_testing`, and the `users_role_organization_check` CHECK constraint exists and is enforced there. Closes the MySQL 8 gap. |
| **CR-14 reproduced** | On MySQL 8.4, `CAST('{"from": "a", "to": "b"}' AS JSON)` returns `{"to": "b", "from": "a"}`. With the old `toBe()` assertion restored temporarily, the rename test **failed on MySQL 8.4 and passed on MariaDB 10.4**. The evidence level was raised from statically confirmed to reproduced. |
| **FC-01 on the target engine** | MySQL 8.4's `utf8mb4_unicode_ci` also treats `ad\u{FE0F}min@example.com` as `admin@example.com`, so the fix is needed in production as well. |
| **Migration cycle on MySQL 8.4** (scratch database, dropped afterwards) | `migrate` (17), `db:seed` twice (5 users, 4 sectors, 5 criteria, no duplicates), `migrate:reset` (17 rolled back, only `migrations` left), `migrate:fresh --seed` (exit 0, 22 tables) |
| **FC-17 (new): `GET /sanctum/csrf-cookie` was registered** | Missed by the §2 route review. Sanctum adds the route by default, under the `web` middleware, although cookie authentication is disabled. The test failed first (204); a probe showed one `sessions` row per call. Fixed with `routes => false` in `config/sanctum.php`, and the path removed from CORS. Regression test: `SanctumConfigTest` › registers no CSRF cookie route… |
| **FC-09 closed at the source** | The welcome page and all web routes were removed ([ADR-013](../decisions/ADR-013-api-only.md)). `ExampleTest` now checks that `GET /` is 404. |

**Updated gate:** PASS for the current surface. **Two gaps remain** (the secret-scanner and MySQL 8 gaps are closed):
- `security-review` cannot run without a git remote ([OQ-28](../open-questions.md#oq-28));
- there is no dynamic testing, because there is no staging environment ([OQ-24](../open-questions.md#oq-24)).
