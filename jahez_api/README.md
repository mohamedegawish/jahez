# Jahez API

JSON API for the **"Smart Industry – Ecosystem" (التحول الصناعي الذكي)** initiative of the Industrial Modernisation Centre (IMC). The platform connects IMC staff, industrial factories and digital-transformation service providers.

**Status:**
- **Phases 1–3 complete:** the foundation, the reference data from the source document, and authentication, accounts, roles and tenant isolation for factories and service providers.
- **Phases 4–6 complete for the owner-approved scope:**
  - the service catalog from the services workbook;
  - provider profiles with IMC approval and a provider directory;
  - the digital readiness assessment: factories answer the 10-question questionnaire, the server scores it (10–40) and classifies the factory (B4 Automation, Basic, Advanced, Smart), with the category's recommended services ([ADR-018](docs/decisions/ADR-018-digital-readiness-assessment.md); it replaced manual classification);
  - a factory-initiated marketplace: requests to several providers, private negotiation, versioned offers in EGP, thread history; an accepted offer records an **agreement**, which is not a contract, invoice or payment;
  - reference data, provider evaluations (written until a scale is approved), the provider review queue.
- **Phase 7:** contract drafts (never binding), invoices and payments exist as boundaries ([ADR-017](docs/decisions/ADR-017-agreements-contracts-billing.md)). **Every money operation is blocked** until the owner sets its rule; the API answers 409 `policy_not_configured` and names the open question. Business rules not yet decided are isolated in `config/jahez.php`.
- **Phase 8** is partly done (the audit log).
- **Phase 9** has had its first pass: security headers, the go-live gate, and a security audit whose findings were fixed.
- **Phase 10** is prepared (k6 scripts), but measuring needs a staging environment.

The project is **API-only**: no web pages and no front-end tooling ([ADR-013](docs/decisions/ADR-013-api-only.md)). See [docs/implementation-plan.md](docs/implementation-plan.md) for the current phase, blockers and approvals, and [docs/open-questions.md](docs/open-questions.md) for decisions awaiting the project owner.

## Stack

| Component | Version |
| --- | --- |
| PHP | 8.2+ with `pdo_mysql`, `mbstring`, `intl`, `bcmath` |
| Laravel | 12.x |
| Database | MySQL-compatible server (target: MySQL; local development currently uses MariaDB 10.4 from XAMPP; see [ADR-004](docs/decisions/ADR-004-database-mysql.md)) |
| Auth | Laravel Sanctum 4, bearer tokens only ([ADR-003](docs/decisions/ADR-003-authentication-bearer-tokens.md)) |
| Tests | Pest 3 (runs against MySQL; the suite passes on MariaDB 10.4 and MySQL 8.4) |
| Quality | Laravel Pint, Larastan (PHPStan level 8), gitleaks (secret scan), k6 (performance scripts) |

Redis is **not** required. Cache and queue use the database drivers; sessions are not used. Node.js is needed only for the Postman runner.

## Local setup

1. Install PHP dependencies:
   ```bash
   composer install
   ```
2. Create your environment file and app key:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
3. Make sure a MySQL-compatible server is running, then create the development and test databases. Adjust the user and password to match your server and `.env`:
   ```bash
   mysql -u root -e "CREATE DATABASE IF NOT EXISTS jahez CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS jahez_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```
   XAMPP's client is at `C:\xampp\mysql\bin\mysql.exe`.
4. Run the migrations and seed the data:
   ```bash
   php artisan migrate
   php artisan db:seed      # reference data; in APP_ENV=local also demo accounts (see below)
   ```
5. Start the server, the queue worker (it sends invitation and password-reset emails) and, if needed, the scheduler (it prunes expired tokens):
   ```bash
   php artisan serve
   php artisan queue:work
   php artisan schedule:work   # optional locally
   ```
6. Check that it works:
   ```bash
   curl http://localhost:8000/api/v1/health
   # {"data":{"status":"ok","checks":{"database":"ok"}}}
   ```

### Accounts

- **Local development:** `php artisan db:seed` with `APP_ENV=local` creates an IMC administrator `test@example.com` and demo members `factory-a@example.test`, `factory-b@example.test`, `provider-p@example.test` and `provider-q@example.test`. Every demo password is `password`. These accounts are **never** created in other environments.
- **Demo dataset (optional, explicit):** `php artisan db:seed --class=DemoDataSeeder` adds 16 synthetic factories, 8 providers, readiness assessments in all four levels, requests, negotiations, agreements, promotions, announcements and change requests, every account on `@demo.jahez.test` with the password `password` (the IMC reviewer is `imc-reviewer@demo.jahez.test`). It runs only with `APP_ENV=local` or `testing`, never as part of `db:seed`, and only creates records: running it again changes nothing. Use an isolated database, for example `DB_DATABASE=jahez_demo php artisan migrate && DB_DATABASE=jahez_demo php artisan db:seed && DB_DATABASE=jahez_demo php artisan db:seed --class=DemoDataSeeder` ([ADR-024](docs/decisions/ADR-024-demo-data.md)).
- **Any other environment:** create the first IMC administrator with `php artisan app:create-admin admin@example.org "Full Name"`, which prompts for the password. Every other account is created by an administrator through `POST /api/v1/users`, and the person receives an emailed link to set their password. There is no public sign-up ([ADR-011](docs/decisions/ADR-011-account-provisioning-and-credentials.md)).
- Locally `MAIL_MAILER=log` writes emails, including reset links, to `storage/logs/laravel.log`. **Never use the log mailer in production.**

## Configuration

| Variable | Purpose | Default |
| --- | --- | --- |
| `DB_*` | Database connection | `mysql`, `127.0.0.1:3306`, `jahez`, `root` |
| `CORS_ALLOWED_ORIGINS` | Comma-separated exact browser origins allowed to call the API | empty (no cross-origin access) |
| `API_RATE_LIMIT_PER_MINUTE` | Requests per minute per user or guest IP | `60` (provisional, not a measured capacity) |
| `TRUSTED_PROXIES` | Comma-separated load-balancer/proxy IPs or CIDRs, or `*`. **Required behind a proxy**, or all guests share one rate limit. | empty (trust none) |
| `FRONTEND_URL` | Client app base URL; reset and invitation emails link to `{FRONTEND_URL}/reset-password` | `http://localhost:3000` |
| `SANCTUM_EXPIRATION` | API token lifetime in minutes (blank or 0 means 480) | `480` |
| `QUEUE_CONNECTION` | Must be an asynchronous driver (`database`) with a worker running in production | `database` |
| `SESSION_DRIVER` | The API uses no sessions; use `array` in production so the web routes store nothing | `database` |

In production, `APP_DEBUG` **must** be `false`. With debug on, server-error responses include exception details.

## Going to production

Follow [docs/deployment.md](docs/deployment.md). Before going live, run the go-live gate on the production host:

```bash
php artisan config:cache
php artisan app:check-production   # exits non-zero on any unsafe setting
```

## Tests and quality checks

```bash
php artisan test --compact                     # full suite (needs the jahez_testing database)
php artisan test --compact --filter=health     # a subset
php artisan test --parallel --processes=4      # parallel; creates jahez_testing_test_N databases
DB_PORT=3307 php artisan test --compact        # the same suite on the local MySQL 8.4 (docs/troubleshooting.md)
composer analyse                               # Larastan, level 8
vendor/bin/pint                                # format code
```

Details: [docs/testing/strategy.md](docs/testing/strategy.md).

## Documentation

Start at [docs/README.md](docs/README.md). Key documents:

- [API endpoints](docs/api-endpoints.md) and [API conventions](docs/api-conventions.md): versioning, error envelope, headers, rate limits
- [Roles and permissions](docs/roles-permissions.md), the [threat model](docs/security/threat-model.md) and the [security findings](docs/security/findings.md)
- [Deployment guide](docs/deployment.md) and the [performance plan](docs/performance/plan.md)
- [Architecture](docs/architecture.md) and [data model](docs/data-model.md)
- [Requirements traceability](docs/requirements-traceability.md) and [open questions](docs/open-questions.md)
- [Decisions (ADRs)](docs/decisions/)
- [Postman collection](postman/README.md)

AI coding agents: read [AGENTS.md](AGENTS.md). The Jahez-specific rules live in `.ai/guidelines/jahez.md` and are merged into `AGENTS.md` by `php artisan boost:update`. Do not edit `AGENTS.md` directly.
