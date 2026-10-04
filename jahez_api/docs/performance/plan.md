# Performance Test Plan

**Status:** prepared in Phase 10 (2026-10-03). **No performance measurement exists yet.** The scripts work (see [results](results/2026-10-03-local-script-validation.md)), but real measurements need a Linux staging environment ([OQ-24](../open-questions.md#oq-24)) and agreed targets ([OQ-35](../open-questions.md#oq-35)). **Do not quote any number from this repository as the platform's capacity.**

## 1. Tools

| Tool | Version | Where |
| --- | --- | --- |
| k6 | 2.2.0 | Local install: `%LOCALAPPDATA%\Programs\k6\k6-v2.2.0-windows-amd64\k6.exe`, from the official release ZIP, SHA-256 checked against the published checksums |
| Scripts | — | `tests/performance/` (JavaScript, k6 ES modules; not run by Pest) |

## 2. Scripts

| Script | What it does | Thresholds |
| --- | --- | --- |
| `smoke.js` | 1 virtual user, `ITERATIONS` (default 5) passes over every read path: health (with security headers), `/me`, own factory, another factory (must be 404), factory list, user list, audit log | Correctness only: every check passes, no unexpected HTTP failure |
| `read-load.js` | `ramping-vus` over `STAGES` (default `10s:5,20s:5,10s:0`), each virtual user repeating a weighted read mix with `THINK_TIME_SECONDS` (default 0.5) between requests. Reports min/median/avg/p90/p95/p99/max, and counts 429 responses as `throttled_responses`. | Correctness only (every check passes). **No latency thresholds** until [OQ-35](../open-questions.md#oq-35) |
| `lib/config.js` | Target URL and accounts from environment variables; logs in once per account in `setup()` and logs out in `teardown()` | Refuses any non-local target unless `TARGET_IS_TEST_SYSTEM=yes` |

The read mix in `read-load.js` (50% `/me`, 20% own factory, 15% factory list, 10% user list, 5% audit log) is a **provisional technical choice**, not a measured usage profile. It is replaced once [OQ-35](../open-questions.md#oq-35) provides one. There are no write scenarios yet. Writes worth testing under load (account creation, organization updates, and later the Phase 6 workflows) need agreed volumes and a disposable database.

## 3. Running

```bash
# Local smoke check (dev database seeded with APP_ENV=local)
k6 run tests/performance/smoke.js -e BASE_URL=http://127.0.0.1:8765

# Staged reads against a test system
k6 run tests/performance/read-load.js -e BASE_URL=https://staging.example -e TARGET_IS_TEST_SYSTEM=yes \
  -e STAGES=1m:10,3m:10,1m:50,3m:50,1m:0 \
  -e ADMIN_EMAIL=… -e ADMIN_PASSWORD=… -e MEMBER_EMAIL=… -e MEMBER_PASSWORD=…
```

- **Rate limits:** the default `api` limit is 60 requests per minute per user, so a few virtual users sharing two accounts soon get 429. For load tests, set `API_RATE_LIMIT_PER_MINUTE` high **on the test system only**, and report it with the results. `php artisan serve` does not pass custom environment variables to its server process. Locally, use the built-in server from `public/` instead: `API_RATE_LIMIT_PER_MINUTE=100000 php -S 127.0.0.1:8765 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`.
- **Never** run against production. The scripts only read data, but they log in and out (4 audit entries per run).

## 4. What a real measurement needs (Phase 10 gate)

1. **Staging** that matches production: Linux, PHP-FPM with OPcache, MySQL 8.4 (the version the suite already passes on), a queue worker, and production-like configuration (`app:check-production` passing, apart from the URLs). The Windows/XAMPP development host is not representative (RK-10).
2. **Targets and usage profile** ([OQ-35](../open-questions.md#oq-35)): expected users per role, peak requests per minute, acceptable p95/p99 and error rate, and data volumes (factories, providers, users, audit entries).
3. **Data volume:** seed staging to the agreed volumes before measuring. List and audit queries behave differently at scale, so run `EXPLAIN` on them there.
4. **Report** for each run: p50/p95/p99, error rate, throughput, and server CPU/memory/DB metrics, with the commit, configuration and stages used. Store it under `docs/performance/results/`.
