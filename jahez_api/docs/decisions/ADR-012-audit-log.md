# ADR-012: Append-only audit log

- **Status:** Accepted (2026-10-03). This is the first slice of Phase 8, built ahead of the blocked phases with the owner's approval ("ok complete", 2026-10-02). Retention is open ([OQ-25](../open-questions.md#oq-25)).
- **Decided by:** technical decision within the approved scope. No business rule is involved. The event list records only actions the API already performs.

## What is recorded

One row in `audit_logs` per security-relevant action (`App\Enums\AuditEvent`):

| Area | Events |
| --- | --- |
| Authentication | `auth.login_succeeded`, `auth.login_failed` (reason: `unknown_account`, `wrong_password`, `deactivated_account`), `auth.login_throttled` (the first refusal of each lock period only), `auth.logged_out`, `auth.password_reset_requested` (written by the queue job), `auth.password_reset_completed` |
| Accounts | `user.created`, `user.renamed`, `user.deactivated`, `user.reactivated`, `user.administrator_created_from_console` |
| Organizations | `factory.created`, `factory.updated`, `service_provider.created`, `service_provider.updated` (only when something changed; sector changes record `from` and `to`) |

Reads are not recorded. Refused authorizations (401/403/404) are not recorded either; they would be an access log, a different decision.

## Shape

- Columns: `event`, `actor_user_id`, `subject_type` + `subject_id`, `ip_address`, `request_id`, `metadata` (JSON), `created_at`. The data model is in [data-model.md](../data-model.md#1-existing-schema-verified).
- `subject_type` stores stable names (`user`, `factory`, `service_provider`), never PHP class names. `AuditLog::record()` accepts only those three model types, and Larastan checks the calls.
- **`actor_user_id` has no foreign key, deliberately.** A foreign-key check takes a shared lock on the actor's `users` row. That lock can deadlock with the administrator row locks in `UserController` (code review CR-12), for example when two administrators deactivate each other, or when one creates an account while another deactivates an administrator. The column is indexed. Users are never deleted (there is no delete endpoint), so the ID stays meaningful.
- The request ID comes from `Context` (ADR-009), so queued jobs keep the ID of the request that dispatched them. A queue worker has no request, so a job that writes an entry carries the client IP it was dispatched with (`SendPasswordResetLink`).
- The `X-Request-Id` value can be supplied by the client. It correlates entries with logs, but it is never evidence of anything.

## Guarantees and their limits

- **Append-only in the application.** `AuditLog` throws on `updating` and `deleting`, and no API endpoint writes to the table. **Not enforced in the database:** a query-builder `update()`/`delete()`, raw SQL or a database user with full rights can still change rows. Database-level enforcement (a separate database user without UPDATE/DELETE on `audit_logs`, or triggers) is not done. It depends on how retention purges will run ([OQ-25](../open-questions.md#oq-25)) and on the production database privileges ([OQ-24](../open-questions.md#oq-24)).
- **Committed with the change it records.** Every entry is written inside the same transaction as the change: login (token), logout, password reset, account, factory and provider changes, and `app:create-admin`. If the audit write fails, the change is rolled back and the client gets a 500 (tests: `AuditTrailTest › atomicity`).
- **No secrets.** Callers never pass passwords or tokens. As a second guard, `record()` drops metadata keys matching `password`, `token` or `secret` at any depth (this is why the token ID is stored as `credential_id`). The emails of failed logins are stored exactly as submitted, so respelling attempts stay visible. They may belong to people without an account, which is personal data ([OQ-25](../open-questions.md#oq-25)).
- **Bounded write amplification.** A throttled login is audited once per lock period, not once per refused request.

## Reading the log

`GET /api/v1/audit-logs`, which needs permission `audit_logs.view` (IMC administrators). Newest first, cursor-paginated. Filters are allow-listed: `event`, `actor_user_id`, `subject_type` + `subject_id` (only together), and `from`/`to` (whole UTC days). The page links keep the filters. A cursor the API did not issue is rejected with 422. See [api-endpoints.md](../api-endpoints.md#audit-log).

## Consequences

- The table grows without limit until a retention period is decided ([OQ-25](../open-questions.md#oq-25)). Indexes `(event, id)`, `(actor_user_id, id)`, `(subject_type, subject_id, id)` and `created_at` serve the filters. Nothing has been measured yet (Phase 10).
- Adding an event means adding an `AuditEvent` case, writing the entry in the same transaction as the change, and adding a test to `tests/Feature/Audit/AuditTrailTest.php`.
