# Phase 3: Authentication, Authorization and Account Lifecycle

**Date:** 2026-10-02 · **Branch:** `phase/03-auth` (from `phase/02-data-model` @ `6b9127d`) · **Result:** exit gate **PASS** (§8)

## 1. Scope

Owner instruction: "ok, complete" (after the Phase 2 report). This was taken as:
- (a) commit Phase 2, done as `6b9127d`;
- (b) accept the four recommended defaults D1–D4;
- (c) complete Phase 3.

| Decision | Applied as | Record |
| --- | --- | --- |
| D1 account creation | No public sign-up; IMC administrators create accounts and invitees set their password through an emailed link; first administrator via `php artisan app:create-admin` | [ADR-011](../decisions/ADR-011-account-provisioning-and-credentials.md) |
| D2 authentication | Sanctum bearer tokens only, 8-hour lifetime, pruning scheduled | [ADR-003](../decisions/ADR-003-authentication-bearer-tokens.md) |
| D3 IMC roles | One `imc_admin` role with named permissions; policies check permissions | [ADR-006](../decisions/ADR-006-roles-and-permissions.md) |
| D4 organizations | Factories and service providers store `name` and sectors only | ADR-006 |

**Delivered:**
- **Endpoints:**
  - `POST /auth/login`, `/auth/logout`, `/auth/forgot-password`, `/auth/reset-password`
  - `GET /me`
  - list/create/show/update for `/factories`, `/service-providers` and `/users` ([api-endpoints.md](../api-endpoints.md))
- **Data:** `factories`, `service_providers`, `factory_sector`, `sector_service_provider`; on users, `role`, `factory_id`, `service_provider_id` and `deactivated_at`, with a CHECK constraint ([data-model.md](../data-model.md)).
- **Authorization:** `Role` and `Permission` enums and three policies. Cross-tenant access returns 404 ([roles-permissions.md](../roles-permissions.md)).
- **Background work:** queued jobs `SendPasswordResetLink` and `SendAccountInvitation`, which create tokens inside the worker; the `app:create-admin` command; daily `sanctum:prune-expired`.
- **Seeding:** a local/testing-only `LocalDemoSeeder` (2 factories, 2 providers, 1 member each; password `password`); the local `test@example.com` account is now an IMC administrator.
- **Docs:**
  - new: ADR-003, ADR-006, ADR-011, roles and permissions, endpoint reference, threat model and security test matrix;
  - updated: the plan, architecture, data model, traceability, open questions (interim D1–D4, new OQ-33), testing strategy, troubleshooting, glossary and agent guidelines.
- **Postman:** a 32-request workflow collection, plus `postman/run-collection.js`.

**Not built:** self-registration and a separate email-verification flow (D1 makes them unnecessary; setting the password verifies the email); MFA and audit logging (Phase 8, [OQ-33](../open-questions.md#oq-33)); IMC department roles ([OQ-21](../open-questions.md#oq-21)).

## 2. Decisions taken during implementation

| Decision | Reason |
| --- | --- |
| Organization link as nullable `users.factory_id` / `users.service_provider_id` plus a DB CHECK, instead of membership tables | One organization per user, real FKs, and the role↔organization rule enforced by the database |
| User→Factory relation named `industrialFactory()` | `HasFactory` already defines the static `factory()` |
| Model named `ServiceProvider` (domain term) despite the Laravel concept | Matches the table, API path and DOC terminology; documented in the class |
| Password policy: at least 12 characters, at most 72 **bytes** (`App\Rules\MaxBytes`) | Laravel's default bcrypt config (`limit` null) silently drops everything after 72 bytes, about 36 Arabic characters |
| Reset and invitation tokens are created inside queued jobs; emails are sent synchronously from the job | Keeps plaintext tokens out of `jobs`/`failed_jobs`, and makes forgot-password do the same work for every email (code review CR-1/CR-3) |
| Fixed cost-12 bcrypt hash for unknown emails (`LoginRequest::UNMATCHABLE_PASSWORD_HASH`) | Equal timing without a cache read or a cold-start `Hash::make` (CR-8) |
| Admin-deactivation lock (`lockForUpdate` on active admins, ordered by id) | Stops two administrators deactivating each other at the same moment (CR-9) |
| `User::$attributes = ['deactivated_at' => null]` | Strict mode threw on freshly created users; the default is mirrored as the migration rule advises |
| The users migration refuses to run when users already exist | Found during the rollback check (§4.5): MySQL DDL is not transactional, so a CHECK failure left the columns half-applied |
| `config/sanctum.php` taken out of the Larastan exclusions | It was edited, so per ADR-005 it must be analysed |

## 3. Changed files

**New (67):**
- Code (37): `app/Enums/{Role,Permission}.php`; `app/Policies/{Factory,ServiceProvider,User}Policy.php`; `app/Http/Controllers/Api/V1/{CurrentUser,Factory,ServiceProvider,User}Controller.php` and `Auth/{AccessToken,PasswordResetLink,NewPassword}Controller.php`; ten Form Requests in `app/Http/Requests/Api/V1/` (and `Auth/`); five resources in `app/Http/Resources/V1/`; `app/Jobs/{SendPasswordResetLink,SendAccountInvitation}.php`; `app/Notifications/{PasswordResetLink,AccountInvitation}.php`; `app/Models/{Factory,ServiceProvider}.php`; `app/Rules/MaxBytes.php`; `app/Console/Commands/CreateAdminUser.php`
- Database (8): five migrations `2026_10_02_19340{8,9}`/`193410` ×2/`193411`; `database/factories/{Factory,ServiceProvider}Factory.php`; `database/seeders/LocalDemoSeeder.php`
- Tests (15): `tests/Feature/Api/V1/Auth/{Login,Logout,PasswordReset}Test.php`; `tests/Feature/Api/V1/{CurrentUser,Factory,ServiceProvider,User}Test.php`; `tests/Feature/Policies/{Factory,ServiceProvider,User}PolicyTest.php`; `tests/Feature/Jobs/{SendPasswordResetLink,SendAccountInvitation}Test.php`; `tests/Feature/Console/CreateAdminUserTest.php`; `tests/Feature/Config/SanctumConfigTest.php`; `tests/Feature/Database/UserOrganizationConstraintTest.php`
- Docs and tools (7): `docs/{api-endpoints,roles-permissions}.md`; `docs/security/{threat-model,security-test-matrix}.md`; `docs/decisions/ADR-{003,006,011}-*.md`; `postman/run-collection.js`; this log

**Modified (34):**
- Config and project files: `.ai/guidelines/jahez.md`, `.env.example`, `AGENTS.md` (regenerated: +7 lines, 0 removed), `README.md`, `config/{api,sanctum}.php`, `phpstan.neon`
- Code and database: `app/Http/Responses/ApiExceptionRenderer.php` (409 for unique violations), `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `database/factories/UserFactory.php`, `database/seeders/DatabaseSeeder.php`
- Routes: `routes/{api,console}.php`
- Tests: `tests/Pest.php`, `tests/Feature/Api/ErrorResponseTest.php`, `tests/Feature/Database/ReferenceDataSeederTest.php`
- Postman: all four files
- Docs: `docs/{README,api-conventions,architecture,data-model,engineering-skills,glossary,implementation-plan,open-questions,requirements-traceability,troubleshooting}.md`, `docs/testing/strategy.md`, `docs/decisions/ADR-005-static-analysis.md`, `docs/phases/phase-02-data-model.md` (one anchor)

**Outside git:**
- The local `.env` gained `FRONTEND_URL` and `SANCTUM_EXPIRATION`.
- The dev database `jahez` was migrated and seeded with the local administrator and demo accounts.
- The Postman run created some demo records in the dev database.
- A scratch database `jahez_migration_check` was created and dropped (twice).

## 4. Test and check results

### 4.1 Test suite (final)

Counts below are from `vendor/bin/pest --list-tests`; each dataset row is one test. Phase 3 added 143 tests: 141 in new files and 2 in existing ones (193 − 50).

| Command | Result |
| --- | --- |
| `php artisan test --compact` | **193 passed** (511 assertions), 0 failed, 0 skipped |
| `php artisan test --parallel --processes=4` | **193 passed** |

| File | Tests | Covers |
| --- | --- | --- |
| `Auth/LoginTest` | 11 | Token, expiry time and user returned; token authenticates; generic 422 (wrong password / unknown / deactivated); hash comparison for unknown email; required fields; 429 after 5 per email+IP; 429 after 20 per account across IPs; accepted at 479 min; 401 at 481 min |
| `Auth/LogoutTest` | 3 | Revokes only the current token; 401 without a token; 401 for an unknown token |
| `Auth/PasswordResetTest` | 13 | Same job and same 202 for active/unknown/deactivated; no token created during the request; 429 forgot limit; reset sets the password, revokes tokens and verifies the email; reused, expired, deactivated and unknown → generic 422; hash comparison for unknown email; policy (12 characters / 72 bytes) |
| `CurrentUserTest` | 5 | Admin / factory member / provider member profiles and permissions; 401 without a token; 401 for a token of an account deactivated afterwards |
| `FactoryTest` | 17 | index (admin paginated; 403 members; 401; per_page > 100 → 422); store (201 with sectors; 422 name; 422 unknown sector; 403 member, nothing created); show (admin; own; **404 other, same body as missing**); update (admin; sectors kept; 403 own member; **404 other, before validation**) |
| `ServiceProviderTest` | 10 | The same contract for providers, including cross-tenant 404s |
| `UserTest` | 24 | Admin listing; 403 member; create factory/provider member with a queued invitation; extra fields ignored; 6 role↔organization validation cases; duplicate email; 403 escalation; show self and colleague; 404 other org; deactivate (tokens and reset links revoked); reactivate; admin deactivates another admin; last-admin lock; self-deactivation 422; role/organization ignored on update; 403 self-promotion; 404 other org update |
| `Policies/*PolicyTest` | 35 (11 + 11 + 13) | Full actor × ability matrices for factories, providers and users (allow / 403 / 404) |
| `Jobs/SendPasswordResetLinkTest` | 4 | Active account gets a working link; nothing for unknown/deactivated; link URL format |
| `Jobs/SendAccountInvitationTest` | 3 | Working set-password link; nothing for a deactivated account; payload holds no token |
| `Console/CreateAdminUserTest` | 4 | Creates a verified admin; refuses a mismatched confirmation, a short password or a taken email |
| `Config/SanctumConfigTest` | 5 | Configured lifetime used; blank / 0 / null / unset → 480 |
| `Database/UserOrganizationConstraintTest` | 7 | CHECK rejects 5 inconsistent rows; accepts a consistent row; FK blocks deleting a factory with members |
| Changes to existing files | +2 | 409 envelope for unique violations; production/demo-seeder guards; local demo accounts created once |

### 4.2 Failures during the phase (not hidden)
1. **Strict mode:** 4 tests failed with `MissingAttributeException [deactivated_at]` on freshly created users. This was a real bug: it would also have broken account creation locally. Fixed by mirroring the default.
2. **`Hash::partialMock()`:** it breaks the hash manager. The test was rewritten with a full expectation.
3. **Larastan:** 14 errors. Model property types came from the migrations; fixed with `@property` docs and accurate return types, without ignores or casts. One more (`?->` on a non-null seeder command) was fixed by using `->`.

### 4.3 Mutation checks (security controls disabled one at a time; every file restored byte-for-byte)

| Control disabled | Failing tests |
| --- | --- |
| Factory ownership check in `FactoryPolicy::view` | 4 |
| Deactivation check in login | 1 |
| Active-account check on tokens (Sanctum callback) | 1 |
| Self-deactivation guard | 1 |
| Token revocation on deactivation | 1 |
| Per-account login lockout | 1 |
| Deactivation check in password reset | 1 |
| `LocalDemoSeeder` environment guard | 1 |
| Last-administrator lock | 1 |
| Token-lifetime fallback | 3 |
| Same-job dispatch for every forgot-password email | 2 |

### 4.4 Static analysis, formatting, audits

| Command | Result |
| --- | --- |
| `composer analyse` (level 8) | **0 errors, 91 files** (`config/sanctum.php` now analysed) |
| `vendor/bin/pint --dirty --format agent` | `passed` (earlier runs fixed import order in 3 files) |
| `composer audit` / `npm audit` | No advisories / 0 vulnerabilities |

### 4.5 Migrations (scratch MySQL, MariaDB 10.4.32)

| Step | Result |
| --- | --- |
| `migrate` (17 migrations) + `db:seed` (local) | OK; 5 users; CHECK `users_role_organization_check` present |
| `migrate:rollback --step=5` with users present | OK; users back to the skeleton columns; 0 CHECK constraints |
| Re-apply with those 5 role-less users | **First attempt: failed at the CHECK after adding the columns (MySQL DDL is not transactional), leaving a half-applied schema.** Fixed: the migration now refuses before any DDL when users exist. Re-check: refused with a clear message, users table untouched; after emptying users, it applies cleanly. |
| `migrate:fresh --seed` | OK; 5 users |
| Dev DB `jahez` | Migrated; seeded with the admin and demo accounts |

### 4.6 Live end-to-end checks (`php artisan serve`, seeded dev DB)
- **curl:**
  - Admin login gives a Bearer token, `expires_at` +8 h and 9 permissions.
  - Factory A member reads factory A (200), factory B (**404**), the factory list (403), and creating an admin (403).
  - A wrong password returns the generic 422.
  - Logout returns 204, and the revoked token then gets 401.
  - Forgot-password returns 202.
- **Queue:** the `jobs` payload contained only the class and the email address, with **no reset token**. The token row was created only when `php artisan queue:work --once` ran the job. The email with the front-end reset link was written to the log mailer. 0 failed jobs.
- **Postman:** the runner ran **32 requests: 99 assertions passed, 0 failed**. Newman was not run (not installed).
  - **Correction (Phase 9):** that run used an identical copy of the runner outside the project. Inside the project, `node postman/run-collection.js` failed, because `package.json` declares `"type": "module"` and the runner was CommonJS. The result itself is real. The runner was fixed in Phase 9 (finding [FC-13](../security/findings.md#phase-9-finecomb-audit-2026-10-03)).

## 5. Code review findings

The `code-review` skill (high effort, auth focus) reviewed the Phase 3 diff. The `security-review` skill could not run because it needs a git remote ([OQ-28](../open-questions.md#oq-28)).

| # | Finding | Severity | Resolution | Regression test |
| --- | --- | --- | --- | --- |
| CR-1 | Forgot-password timing revealed accounts (token bcrypt and DB writes only for known accounts) | Medium | All work moved into `SendPasswordResetLink`, dispatched for every email | PasswordResetTest › queues the same job… |
| CR-2 | Lockout keyed per email+IP only, so distributed brute force went unlimited | Medium | Added a per-account limit (20 per 15 minutes) | LoginTest › returns 429 for an account after twenty… |
| CR-3 | Plaintext reset/invitation tokens stored in `jobs`/`failed_jobs` payloads | High | Tokens created inside the jobs; notifications sent synchronously from there | PasswordResetTest › creates no reset token…; SendAccountInvitationTest › serializes only…; verified end-to-end |
| CR-4 | A deactivated account could complete a reset (race) | Medium | Reset callback refuses inactive accounts; jobs skip them | PasswordResetTest › rejects a valid token of a deactivated account… |
| CR-5 | `SANCTUM_EXPIRATION` blank/null/0 meant instantly expired tokens, locking everyone out | Medium | Falls back to 480 | Config/SanctumConfigTest |
| CR-6 | `LocalDemoSeeder` unguarded when run directly | Medium | Guard moved inside the seeder | ReferenceDataSeederTest › creates no demo accounts when the demo seeder is run directly in production |
| CR-7 | Reset-password timing revealed accounts | Low | Hash comparison against the fixed hash for unknown emails | PasswordResetTest › compares a hash for an unknown email… |
| CR-8 | Dummy hash read from the cache, with a cold-start `Hash::make` | Low | Fixed cost-12 constant | LoginTest › compares a password hash… |
| CR-9 | Two admins deactivating each other could leave none active | Medium | Row-locked check that the acting admin is still active | UserTest › rejects deactivating an administrator when the acting administrator was deactivated meanwhile… |
| CR-10 | Factory and provider controllers/policies duplicated | Low (maintainability) | **Not changed, by decision:** the two organization types are expected to diverge (provider evaluation vs factory assessments); both are covered by identical policy matrices | — |

## 6. Security and performance notes
- **Implemented controls** are tracked as threats T1–T16 in the [threat model](../security/threat-model.md) and in the [security test matrix](../security/security-test-matrix.md).
- **Still open:**
  - audit logging (T17, Phase 8);
  - security headers and HTTPS (T18, Phase 9);
  - MFA ([OQ-33](../open-questions.md#oq-33));
  - IMC confirmation of the provisional thresholds (OQ-33);
  - a production queue worker and real mailer are **required** (RK-17, RK-18).
- **Performance:** nothing was measured. Notes for Phase 10:
  - Each login costs one bcrypt comparison (cost 12 in production) by design.
  - List endpoints eager-load sectors and organizations; strict mode would throw on N+1 queries in tests.

## 7. Open questions affected
- Interim answers recorded: [OQ-18](../open-questions.md#oq-18) (D1), [OQ-19](../open-questions.md#oq-19)/[OQ-20](../open-questions.md#oq-20) (D4), [OQ-21](../open-questions.md#oq-21) (D3), [OQ-22](../open-questions.md#oq-22) (D2).
- New: [OQ-33](../open-questions.md#oq-33) (security parameters, MFA).

## 8. Exit gate

| Criterion (master prompt, Phase 3) | Status | Evidence |
| --- | --- | --- |
| Sign-in/out, password recovery, token lifecycle, account review flows (as required) | ✅ | §1; verification via the set-password link (D1) |
| Admin / factory / provider roles with granular permissions and ownership rules | ✅ | ADR-006; policies; policy matrices |
| Never trust role, owner or tenant IDs from the client | ✅ | Not fillable; ignored on update; CHECK constraint; tests in §4.1 |
| Tests: credentials, expiry, revocation, rate limiting | ✅ | LoginTest, LogoutTest, CurrentUserTest |
| Tests: unauthenticated and unauthorized-role access | ✅ | 401/403 tests per endpoint |
| Tests: **IDOR/BOLA across two factories and two providers** | ✅ | FactoryTest, ServiceProviderTest, UserTest, policy matrices; live curl; Postman folder 13 |
| Tests: privilege escalation and mass assignment | ✅ | UserTest (escalation, ignored fields, self-promotion) |
| Tests: duplicate account; reset-token expiry and reuse | ✅ | UserTest duplicate email (422) and the 409 backstop; PasswordResetTest |
| Responses leak no private records or exception internals | ✅ | ErrorResponseTest; resource field allow-lists; `/me` excludes credentials |
| **Every protected endpoint has positive and negative authorization tests** | ✅ | §4.1. Every route under `auth:sanctum` has an allowed case and at least one 401/403/404 case. |

**Gate: PASS.** The interim decisions (D1–D4) and the provisional security values remain open to owner confirmation (OQ-18/21/22/33). They do not block the next phase.

## 9. Next phase
- **Phase 4 (catalog, provider profiles, provider evaluation)** is still **blocked** on the services workbook ([OQ-01](../open-questions.md#oq-01)) and on the evaluation scoring scale and pass mark ([OQ-13](../open-questions.md#oq-13), [OQ-14](../open-questions.md#oq-14)).
- **Phase 5** is blocked on the readiness index and thresholds ([OQ-06](../open-questions.md#oq-06), [OQ-07](../open-questions.md#oq-07)).
- **Phase 6** is blocked on the operating model ([OQ-03](../open-questions.md#oq-03)).
- **Work possible without owner input:**
  - **Phase 8, audit-log slice:** recording logins and account changes would close T17.
  - **Phase 9 hardening items:** security headers, a secret scanner (needs approval), and the full `finecomb` audit.
- **Commit:** Phase 3 changes are **uncommitted** on `phase/03-auth`, ready for review with `git diff phase/02-data-model`.
