# ADR-011: Account provisioning, passwords and abuse controls

- **Status:** Accepted (2026-10-02, Phase 3), as decision **D1**. The numeric values are **provisional** security defaults ([OQ-33](../open-questions.md#oq-33)).
- **Decided by:** Project owner accepted the recommended default ("ok, complete"). Revisit when [OQ-18](../open-questions.md#oq-18) (the registration model) is answered.

## Account provisioning
- **No public self-registration.** Only an IMC administrator creates accounts (`POST /api/v1/users`). The first administrator is created on the server with `php artisan app:create-admin {email} {name}`, which prompts for the password so it never appears in shell history.
- A new account gets a random 64-character password nobody knows. The `SendAccountInvitation` job then creates a set-password token and emails an invitation link to `{FRONTEND_URL}/reset-password?token=…&email=…`. The invitee sets a password through `POST /api/v1/auth/reset-password`. Doing so also marks the email as verified, so no separate verification flow is needed.
- Deactivation (`PATCH /api/v1/users/{id}` with `is_active: false`) revokes every token and pending reset link. An administrator cannot deactivate themselves. Deactivating an administrator locks the active-admin rows, so two administrators cannot deactivate each other at the same moment and leave none active.

## Password policy (`Password::defaults()`)
- **At least 12 characters and at most 72 bytes**, with no composition rules (NIST SP 800-63B style). The byte limit exists because bcrypt silently ignores everything after 72 bytes, which is about 36 Arabic characters (`App\Rules\MaxBytes`).
- The compromised-password check (`uncompromised()`) is **not** enabled: it calls an external service on every password change. It can be added if the owner wants it.

## Abuse controls (provisional values)

| Control | Value |
| --- | --- |
| Failed logins per email + IP | 5 per minute, then 429 with `Retry-After` |
| Failed logins per account from all addresses | 20 per 15 minutes, against distributed brute force (added after code review) |
| Forgot / reset password | 5 per minute per IP |
| General API limit | 60 per minute per user or IP (Phase 1) |

## Not revealing which accounts exist
- **Login:** unknown email, wrong password and deactivated account return the same 422 message. An unknown email is compared against a fixed cost-12 bcrypt hash (`LoginRequest::UNMATCHABLE_PASSWORD_HASH`), so it costs one hash comparison, like a known email. That cost must stay in step with `BCRYPT_ROUNDS`.
- **Forgot password:** always 202 with the same message. The request only dispatches `SendPasswordResetLink(email)`. The account lookup, token hashing and email all happen in the queue worker, so the response does identical work for every email.
- **Reset password:** every failure gets one message. An unknown email also costs one hash comparison against the fixed hash.

## Reset tokens never stored in plaintext
Reset and invitation tokens are created **inside** the queue jobs and emailed synchronously from there. Job payloads hold only the email address or the user's identifier, never a token. This was found in code review and is verified end-to-end against the database (see the Phase 3 log).

## Operational requirements
- A **queue worker** (`php artisan queue:work`) must run, or invitation and reset emails are never sent. With `QUEUE_CONNECTION=sync`, the timing protection of the forgot-password endpoint is lost.
- **Production must not use `MAIL_MAILER=log`.** The log mailer writes reset links, and therefore valid tokens, into `storage/logs/laravel.log`.
- `FRONTEND_URL` must point at the client application that implements the `/reset-password` page.
