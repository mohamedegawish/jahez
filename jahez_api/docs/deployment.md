# Deployment Guide

**Status:** first version, Phase 9 (2026-10-03). **It has not been exercised on a real server yet**: there is no staging environment and the hosting is undecided ([OQ-24](open-questions.md#oq-24)). Phase 11 verifies it end to end. Every requirement below comes from code or a recorded decision; the reference is given for each.

## 1. Go-live gate

Run this on the production host after configuring the environment and caching the configuration:

```bash
php artisan config:cache
php artisan app:check-production
```

The command prints a PASS / WARN / FAIL table and exits non-zero on any FAIL. **Do not go live with a FAIL.** Review every WARN.

| Check | FAIL / WARN when | Why |
| --- | --- | --- |
| APP_ENV is production | not `production` | Strict model mode and demo seeding are tied to the environment |
| APP_DEBUG is off | `APP_DEBUG=true` | Debug responses include exception details; Laravel Boost routes are enabled |
| APP_KEY is set | empty | Encryption |
| APP_URL / FRONTEND_URL use HTTPS | not `https://` | Reset and invitation links point to `FRONTEND_URL` |
| Mailer sends real email | `log` or `array` | The log mailer writes reset links (valid tokens) to the log ([ADR-011](decisions/ADR-011-account-provisioning-and-credentials.md)) |
| Queue is asynchronous | `sync` | Reset and invitation emails are sent by jobs; with `sync` the forgot-password timing protection is lost |
| CORS has no wildcard origin | `*` in `CORS_ALLOWED_ORIGINS` | Deny-by-default CORS |
| Hash driver is bcrypt | `HASH_DRIVER` is not `bcrypt` | The login timing hash is bcrypt; another driver makes unknown emails fail with 500 (finding FC-06) |
| BCRYPT_ROUNDS matches the login timing hash | not 12 | Unknown and known emails must cost the same |
| Database is MySQL | another driver | [ADR-004](decisions/ADR-004-database-mysql.md) |
| Trusted proxies configured (WARN) | unset, `*` or `**` | Unset: every guest shares the proxy's rate limit. `*`: safe only if clients cannot reach the app except through the proxy |
| Log level (WARN) | `debug` | Verbose |
| Sessions are not stored (WARN) | `SESSION_DRIVER` is not `array` | The API uses no sessions and has no web routes ([ADR-013](decisions/ADR-013-api-only.md)); `array` keeps it that way if a route that starts sessions is ever added (findings FC-09, FC-17) |

## 2. Environment

Start from `.env.example`. Production values that differ from it:

| Variable | Production value |
| --- | --- |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL`, `FRONTEND_URL` | the real HTTPS URLs |
| `MAIL_MAILER` and `MAIL_*` | a real mail provider |
| `QUEUE_CONNECTION` | `database` (or Redis if [OQ-24](open-questions.md#oq-24) provides it) |
| `SESSION_DRIVER` | `array` |
| `TRUSTED_PROXIES` | the load balancer's addresses or CIDR |
| `CORS_ALLOWED_ORIGINS` | the exact client origins ([OQ-22](open-questions.md#oq-22)) |
| `LOG_LEVEL` | `warning` or `error` |
| `BCRYPT_ROUNDS`, `HASH_DRIVER` | `12`, `bcrypt` (unchanged) |

**Business policies** (`config/jahez.php`). Leave them unset until the owner decides the linked question; `app:check-production` fails on an invalid value:

| Variable | Effect | Decided by |
| --- | --- | --- |
| `JAHEZ_PROVIDER_REQUIRED_FIELDS` | Profile fields required before approval (comma-separated) | [OQ-36](open-questions.md#oq-36) |
| `JAHEZ_DIRECTORY_SHOWS_CONTACT_DETAILS` | `true` shows contact details in the directory | [OQ-37](open-questions.md#oq-37) |
| `JAHEZ_PROVIDER_EVALUATION_SCALE_MAX`, `JAHEZ_PROVIDER_EVALUATION_PASS_MARK` | Enable evaluation scores, the weighted total and the pass-mark result | [OQ-13](open-questions.md#oq-13) |
| ~~`JAHEZ_INVOICE_ISSUER`, `JAHEZ_INVOICE_NUMBER_PREFIX`, `JAHEZ_TAX_RATE_PERCENT`, `JAHEZ_REVENUE_SHARE_PERCENT`~~ | **No longer read** (ADR-023): these rules are approved policies in «الإعدادات المالية والتعاقدية». `app:check-production` fails while any is set | [ADR-023](decisions/ADR-023-financial-and-contract-policies.md) |
| `JAHEZ_BUSINESS_TIMEZONE` | Calendar in which policy effective dates and due dates are read (default `Africa/Cairo`); checked by `app:check-production` | [ADR-023](decisions/ADR-023-financial-and-contract-policies.md) |
| `JAHEZ_PAYMENT_GATEWAY` | Names a gateway adapter registered in `config/jahez.php` `billing.gateways` (none ships). Its callback URL is `/api/v1/payment-gateways/{key}/callback`; the scheduler runs `payments:reconcile` every 15 minutes | [OQ-16](open-questions.md#oq-16) |
| `JAHEZ_FACTORY_REQUIRED_FIELDS` | Factory onboarding checklist (comma-separated; default `sectors`); guidance only, never blocks an assessment. Checked by `app:check-production` | [OQ-19](open-questions.md#oq-19) |
| `JAHEZ_DOCUMENTS_DISK` | Disk for logos and registration documents (default `local`, private). Must be a configured disk that is neither public nor served (checked by `app:check-production`); back it up with the database. PHP `upload_max_filesize`/`post_max_size` ≥ 16M | [ADR-019](decisions/ADR-019-self-registration-documents-and-legal-changes.md) |
| `JAHEZ_LOGO_MAX_KB`, `JAHEZ_LEGAL_DOCUMENT_MAX_KB`, `JAHEZ_REGISTRATIONS_PER_HOUR` | Upload limits (2048, 5120) and public registrations per IP per hour (10): technical defaults | [ADR-019](decisions/ADR-019-self-registration-documents-and-legal-changes.md), [OQ-33](open-questions.md#oq-33) |
| `JAHEZ_FACTORY_APPROVAL_REQUIRED` | `true` (default): only an IMC-approved factory sends service requests; `false`: any factory does, approval stays informational | [OQ-46](open-questions.md#oq-46), [ADR-021](decisions/ADR-021-ministry-administration.md) |
| `MAIL_MAILER` and `MAIL_*` (Gmail) | `MAIL_MAILER=smtp`, `MAIL_SCHEME=smtp`, `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`, `MAIL_USERNAME` (the sending account), `MAIL_PASSWORD` (a Google App Password; needs 2-step verification on the account), `MAIL_FROM_ADDRESS` (that account or a verified alias). Verify with `php artisan app:mail-check <address>`, then confirm the message in that mailbox. Keep a queue worker running: notification emails are queued (3 tries, then `failed_jobs`) | [ADR-021](decisions/ADR-021-ministry-administration.md) |
| `JAHEZ_MARKETPLACE_SINGLE_AWARD` | `true` (default): the first accepted offer closes the request's other threads; `false`: several providers can be agreed on one request | [OQ-38](open-questions.md#oq-38) |

Optional, not applied: `SANCTUM_TOKEN_PREFIX` would make leaked tokens recognisable by secret scanners (finding FC-16). Decide it before go-live.

### Financial policy permissions (ADR-023)

After the first deployment nobody can prepare or approve financial policies or record manual payments. Grant each to the named administrators the owner designates (OQ-49), from the server console:

```bash
php artisan jahez:permissions grant finance.officer@imc.example financial_policies.manage --reason="Designated by ..."
php artisan jahez:permissions grant finance.director@imc.example financial_policies.approve --reason="Designated by ..."
php artisan jahez:permissions list
```

Give `manage` and `approve` to different people; the API also refuses an approval by whoever prepared a version.

## 3. Release steps

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --force          # reference data only; demo accounts are never created outside local/testing
# Never run DemoDataSeeder in a release: it is not part of db:seed and refuses to run outside local/testing (ADR-024).
php artisan config:cache && php artisan route:cache
php artisan app:check-production
php artisan app:create-admin admin@example.org "Full Name"   # first deployment only; prompts for the password
```

- `--no-dev` also removes Laravel Boost and the MCP middleware, which exist only for development.
- There is no front-end build: no `npm` step ([ADR-013](decisions/ADR-013-api-only.md)).
- Run `gitleaks git --log-opts="--all" --redact .` on the release commit (no leaks expected), and the test suite on the production MySQL version.
- **Drain the queue before deploying a change to a queued job's constructor** (`php artisan queue:work --stop-when-empty` on the old release). Jobs serialized by the old code are missing the new properties.

## 4. Processes

| Process | Command | Required because |
| --- | --- | --- |
| Queue worker | `php artisan queue:work` (kept running by a process supervisor) | Reset and invitation emails ([ADR-011](decisions/ADR-011-account-provisioning-and-credentials.md)) |
| Scheduler | `* * * * * php artisan schedule:run` | `sanctum:prune-expired` (daily) |

## 5. Web server

- Serve only the `public/` directory, over HTTPS. The application sends HSTS on requests it sees as HTTPS (which, behind a proxy, needs `TRUSTED_PROXIES`); the proxy may also set it.
- Set `expose_php = Off` in `php.ini`.
- The local storage disk is not served by Laravel (`serve => false`), so no `/storage/...` route exists.

## 6. Data

- **Backups and retention** are not defined yet ([OQ-25](open-questions.md#oq-25)). The `audit_logs` table grows without limit until then ([ADR-012](decisions/ADR-012-audit-log.md)).
- The audit log is append-only in the application only. To protect it in the database as well, the application's database user would need to lose UPDATE and DELETE on `audit_logs`. That is not configured, pending the retention decision.
