# 2026-10-03: Local script validation (not a performance result)

**Purpose:** prove that the k6 scripts run and that their checks pass. **These timings are not performance evidence.** The host is a Windows development machine (RK-10). PHP's built-in server handles one request at a time, without OPcache tuning or PHP-FPM, against MariaDB 10.4 with a handful of rows.

| Item | Value |
| --- | --- |
| Commit | `368ca11` plus the uncommitted Phase 10 preparation branch `phase/10-tooling-and-api-only` |
| Server | `php -S 127.0.0.1:8765` (from `public/`), PHP 8.2.12, `API_RATE_LIMIT_PER_MINUTE=100000`, dev database `jahez` (MariaDB 10.4.32, local demo seed) |
| Tool | k6 v2.2.0 (windows/amd64) |

## `smoke.js` (1 VU, 5 iterations)

- Checks: **42 / 42 passed** (login ×2, then per iteration: health, security headers, `/me`, own factory, other factory → 404, factory list, user list, audit log).
- HTTP requests: 40, `http_req_failed` 0.00%.
- Thresholds: `checks rate==1` ✓, `http_req_failed rate==0` ✓.

## `read-load.js` (default stages `10s:5,20s:5,10s:0`)

- Iterations: 246. Checks: **248 / 248 passed**. HTTP requests: 251, `http_req_failed` 0.00%. No 429 responses.
- Threshold: `checks rate==1` ✓.
- Raw request duration, for script debugging only: median 109 ms, p95 190 ms, p99 247 ms, max 539 ms.

## Not shown by this run
Capacity, scalability, behaviour under write load, behaviour at production data volumes, or anything about MySQL 8.4 or Linux. See the [plan](../plan.md#4-what-a-real-measurement-needs-phase-10-gate).
