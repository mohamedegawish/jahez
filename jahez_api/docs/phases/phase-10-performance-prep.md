# Phase 10 (preparation): Tooling, API-only, Performance Scripts

**Date:** 2026-10-03 · **Branch:** `phase/10-tooling-and-api-only` (from `phase/08-09-audit-and-hardening` @ `368ca11`) · **Result:** preparation **complete**; the Phase 10 gate is **not reached**, because there are no measurements yet (§6)

## 1. Scope

Owner answers (2026-10-03), given after the Phase 8/9 commit:
- **Tools:** install **gitleaks**, **k6** and **MySQL 8.4 locally**.
- **OQ-34:** the project is **API-only**, so remove the welcome page.
- **Git remote:** none yet.

**Delivered:**
- **Tools installed:**
  - gitleaks 8.30.1, through winget: a portable package at user scope, hash-verified by winget.
  - k6 2.2.0, from the official ZIP, SHA-256 matched against `k6-v2.2.0-checksums.txt`.
  - MySQL 8.4.9, from the official ZIP:
    - MD5 matched against the published `.md5`;
    - the GPG signature verified as "MySQL Release Engineering", fingerprint `BCA4 3417 C3B4 85DD 128E C6D4 B7B3 B788 A8D3 785C`;
    - runs on `127.0.0.1:3307` with no Windows service.
- **Phase 9 follow-ups:** full-history secret scan; the test suite and the migration cycle on MySQL 8.4; CR-14 reproduced; a new finding FC-17. Details are in the [Phase 9 log](phase-09-hardening.md#11-follow-up-2026-10-03).
- **API-only ([ADR-013](../decisions/ADR-013-api-only.md)):**
  - removed: web routes, the welcome page, Vite/Tailwind, `package.json`/`package-lock.json`, and `node_modules` (local);
  - Sanctum routes disabled;
  - CORS limited to `api/*`;
  - `SESSION_DRIVER=array` in `.env.example`;
  - the Postman runner renamed to `run-collection.mjs`.
- **Performance scripts:** `tests/performance/{smoke,read-load}.js` and `lib/config.js`, the [performance plan](../performance/plan.md), and a [script-validation record](../performance/results/2026-10-03-local-script-validation.md).
- **Docs:** ADR-013, OQ-34 answered, new [OQ-35](../open-questions.md#oq-35) (performance targets), and the updates in §5.

## 2. Decisions taken during implementation

| Decision | Reason |
| --- | --- |
| Install k6 and MySQL from the official ZIPs, not winget | Both winget packages are MSI installers that need administrator rights, and MySQL's would also set up a service. The ZIPs install at user scope and can be removed by deleting a folder. |
| MySQL 8.4 on port 3307, bound to 127.0.0.1, `root` without a password, not a service | Runs beside XAMPP's MariaDB (3306) and matches the existing local `.env` credentials. It is reachable only from this machine and started only when needed. |
| Keep MariaDB 10.4 as the default local database; run MySQL 8.4 with `DB_PORT=3307` | Local tools and the dev data stay as they are. Phase 6 concurrency work and any pre-release run use 8.4 ([testing strategy](../testing/strategy.md#2-commands)). |
| Remove the `composer run dev` script | It started Vite through `npx concurrently`. Without a lockfile, `npx` would fetch the package at run time, an unpinned download, so the separate commands in the README are used instead. |
| Keep `resources/views/` (empty) | `view:cache`, run by `php artisan optimize`, fails when the view path does not exist |
| Performance scripts have correctness thresholds only | Response-time targets are a business decision ([OQ-35](../open-questions.md#oq-35)). Inventing them would breach the master prompt. |
| Scripts refuse non-local targets unless `TARGET_IS_TEST_SYSTEM=yes` | Load must never reach production by accident |

## 3. Tests and checks

| Check | Result |
| --- | --- |
| `php artisan test --compact` (MariaDB 10.4) | **284 passed** (825 assertions) |
| `DB_PORT=3307 php artisan test --compact` (MySQL 8.4.9) | **284 passed** (825 assertions) |
| `php artisan test --parallel --processes=4` on both engines | **284 passed** on each; Larastan level 8 0 errors; Pint passed; `composer audit` no advisories |
| New test | `Config/SanctumConfigTest` › registers no CSRF cookie route…: failed before the fix (204), passes after |
| Changed tests | `ExampleTest` now checks that `GET /` is 404 and `/up` is 200, instead of `GET /` 200 (approved removal). `RequestIdTest` and `SecurityHeadersTest` use `/up` instead of `/` for their outside-the-API case. |
| k6 `smoke.js` (local, 1 VU × 5) | 42/42 checks, 0 failed requests |
| k6 `read-load.js` (local, 5 VUs, 40 s) | 248/248 checks, 0 failed requests, no 429 |
| Postman (`node postman/run-collection.mjs`, no `package.json`) | 39 requests, 160 assertions passed, 0 failed |

**Failure during the phase (not hidden):** the first k6 smoke run failed in `setup()`. PHP's built-in server had been started from the project root, but Laravel's `server.php` expects `public/` as its working directory, so PHP answered with a fatal error **and status 200**. The login check had passed on that 200. It now requires a token in the JSON body, and the server is started from `public/` (documented in the [plan](../performance/plan.md#3-running)).

## 4. Outside git

- **Installed:**
  - gitleaks (winget, user scope);
  - `%LOCALAPPDATA%\Programs\k6\`;
  - `%LOCALAPPDATA%\Programs\mysql-8.4.9-winx64\` (with `my.ini`) and its data directory `%LOCALAPPDATA%\Programs\mysql-8.4-data\`;
  - the downloaded archives and signatures in `%LOCALAPPDATA%\Programs\downloads\`.
- **MySQL 8.4:** database `jahez_testing`, plus parallel `jahez_testing_test_N`. A scratch `jahez_migration_check` was created and dropped twice.
- **Dev database:** k6 and Postman logins and logouts added audit entries; Postman created demo records.
- **Removed:** `node_modules/` (ignored by git).

## 5. Docs updated

- New: ADR-013, `docs/performance/plan.md`, `docs/performance/results/2026-10-03-local-script-validation.md`, this log.
- Updated:
  - `README.md`, `postman/README.md`, `.env.example`, `.ai/guidelines/jahez.md` (and regenerated `AGENTS.md`; `boost:update` also removed the now-unused `tailwindcss-development` skill from `boost.json` and `.claude/skills/`);
  - `docs/{README,api-conventions,architecture,data-model,engineering-skills,implementation-plan,open-questions,troubleshooting,deployment}.md`;
  - `docs/security/{findings,threat-model,security-test-matrix}.md`, `docs/testing/strategy.md`;
  - `docs/decisions/ADR-004-database-mysql.md`;
  - `docs/phases/phase-09-hardening.md` (follow-up section).

## 6. Exit gate

| Criterion (master prompt, Phase 10) | Status |
| --- | --- |
| k6 or Locust scripts in `tests/performance/` | ✅ written and validated locally |
| Staged load on a Linux staging environment; realistic read/write mix | ⛔ no staging ([OQ-24](../open-questions.md#oq-24)); no usage profile ([OQ-35](../open-questions.md#oq-35)) |
| p50/p95/p99, error rate, resource usage; `EXPLAIN` on critical queries | ⛔ needs staging at agreed data volumes |
| No capacity claims without measured evidence | ✅ none made; the plan and the validation record say so |

**Gate: not reached.** The preparation is complete. Measuring needs staging and OQ-35.
