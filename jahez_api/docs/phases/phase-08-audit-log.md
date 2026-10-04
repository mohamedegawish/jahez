# Phase 8 (partial): Audit Log

**Date:** 2026-10-02 to 2026-10-03 · **Branch:** `phase/08-09-audit-and-hardening` (from `phase/03-auth` @ `4446cba`) · **Result:** audit-log slice **complete**; the rest of Phase 8 is **not started** (§6)

## 1. Scope

Owner instruction: "ok complete", in answer to "Should I commit Phase 3, and continue with the audit log and hardening while you gather those inputs?". Phases 4–7 are blocked on owner input, so this branch builds work that needs none:
- this log: the **audit-log slice of Phase 8** (threat T17);
- [Phase 9 log](phase-09-hardening.md): the first hardening pass, which also reviewed this slice.

The two phases share one branch. The test, review and gate results for both are in the Phase 9 log.

**Delivered:**
- `audit_logs` table and the `AuditLog` model: append-only in the application, metadata sanitised, stable subject names, no foreign key on the actor ([ADR-012](../decisions/ADR-012-audit-log.md)).
- 16 events (`App\Enums\AuditEvent`) recorded by login, logout, password reset (request and completion), account creation/rename/deactivation/reactivation, factory and provider creation and update, and `app:create-admin`. Each entry is written in the same transaction as its change.
- `GET /api/v1/audit-logs` for IMC administrators (new permission `audit_logs.view`): newest first, cursor-paginated, allow-listed filters, whole UTC days.
- Docs: ADR-012, plus the endpoint, permission, data-model and traceability updates listed in the [Phase 9 log](phase-09-hardening.md#7-changed-files).
- Postman: folder **12 Admin & Reports** (5 requests), and a 403 case in folder 13.

**Not built:** notifications beyond the existing emails ([OQ-26](../open-questions.md#oq-26)), KPI reports ([OQ-27](../open-questions.md#oq-27)), audit retention and purging ([OQ-25](../open-questions.md#oq-25)), and database-level immutability ([ADR-012](../decisions/ADR-012-audit-log.md)).

## 2. Decisions taken during implementation

| Decision | Reason |
| --- | --- |
| Record writes and authentication events, not reads or refused requests | Security-relevant changes without turning the table into an access log |
| `subject_type` stores `user` / `factory` / `service_provider`, not class names | Stable across refactoring; class names are internal details |
| No foreign key on `actor_user_id` | A foreign-key check locks the actor's `users` row and can deadlock with the administrator locks (code review CR-12) |
| Metadata keys matching `password`/`token`/`secret` are dropped | A second guard behind "never pass secrets"; the token ID is therefore stored as `credential_id` |
| Failed-login email stored as submitted | Respelling attacks stay visible (CR-20) |
| Throttled logins audited once per lock period | Bounded writes under attack (CR-19) |
| Queued jobs pass the client IP explicitly | The worker has no request (CR-16) |
| Cursor pagination for the audit log | Stable paging on an append-only table, without counting rows |

## 3. Changed files (audit slice)

- **New:** `app/Enums/AuditEvent.php`, `app/Models/AuditLog.php`, `app/Policies/AuditLogPolicy.php`, `app/Http/Controllers/Api/V1/AuditLogController.php`, `app/Http/Requests/Api/V1/ListAuditLogsRequest.php`, `app/Http/Resources/V1/AuditLogResource.php`, `database/migrations/2026_10_02_202346_create_audit_logs_table.php`; tests `tests/Feature/Models/AuditLogTest.php`, `tests/Feature/Audit/AuditTrailTest.php`, `tests/Feature/Api/V1/AuditLogTest.php`, `tests/Feature/Policies/AuditLogPolicyTest.php`; `docs/decisions/ADR-012-audit-log.md`, this log.
- **Modified to record events:** `app/Enums/Permission.php`, `app/Http/Requests/Api/V1/Auth/LoginRequest.php`, `app/Http/Controllers/Api/V1/Auth/{AccessToken,NewPassword,PasswordResetLink}Controller.php`, `app/Http/Controllers/Api/V1/{User,Factory,ServiceProvider}Controller.php`, `app/Jobs/SendPasswordResetLink.php`, `app/Console/Commands/CreateAdminUser.php`, `routes/api.php`.

## 4. Results

The audit-slice test files hold 52 tests: `Api/V1/AuditLogTest` 23, `Audit/AuditTrailTest` 21, `Models/AuditLogTest` 5, `Policies/AuditLogPolicyTest` 3. They are part of the 283-test run in the [Phase 9 log](phase-09-hardening.md#4-tests-and-checks). End-to-end checks on the dev database (`php artisan serve` plus a real queue worker):
- The Postman folder 12 passed.
- A forgot-password request for a demo account produced an `auth.password_reset_requested` entry from the worker, with the client IP and the request ID of the original request.
- The job payload held the email and IP only, with no token.
- No audit entry outside `auth.login_succeeded` contains the word "token".

## 5. Open questions affected

- [OQ-25](../open-questions.md#oq-25): now also covers audit-log retention, the emails stored for failed logins, and database-level immutability.

## 6. Exit gate (Phase 8, partial)

| Criterion (master prompt, Phase 8) | Status |
| --- | --- |
| Append-only audit log of sensitive actions (actor, action, target, request ID, timestamp; no secrets) | ✅ ADR-012; tests in §4 |
| Queued notifications with retries | Existing reset and invitation jobs only; channels and events wait for [OQ-26](../open-questions.md#oq-26) |
| KPI reports, timezone-aware date ranges | ⛔ blocked on [OQ-27](../open-questions.md#oq-27); the audit log's UTC day filter is the only date range so far |

**Gate: partial.** The audit-log slice is complete and tested. The rest of Phase 8 stays blocked on OQ-26 and OQ-27.
