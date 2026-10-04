# Phase log: Phase 2 & 3 gap closure and production-readiness verification (2026-10-04)

Decision record: [ADR-022](../decisions/ADR-022-announcements-and-listing-resubmission.md). Nothing was committed or pushed. The broken local MariaDB data directory (port 3306) was not touched.

## Gaps confirmed and closed
| Gap | Found | Change |
| --- | --- | --- |
| Landing-page ads were browser-local mock data (`AppContext` + `localStorage`, seeded from `mockAds`) | yes | `public_announcements` with IMC administration and a public, token-free feed; the landing strip, ad details page and admin page read the API |
| `AppProvider` still wrote mock factories, invoices and contracts to every visitor's `localStorage`, though no page used it any more | yes (found on the way) | provider, `AppContext.tsx`, `mockData.ts`, `mockAds.ts` and the local-only `AdFormModal.tsx` removed |
| A rejected listing could not be resubmitted | yes | `POST /service-providers/{id}/services/{catalogService}/resubmit`; button on «خدماتي» |
| Catalog activation | reviewed | **not added**: ADR-010 rule 4 / ADR-014 make the catalog seeder-owned; listing suspension is the runtime mechanism (ADR-022 §3) |
| Postman collection stopped at Phase 1 and **already failed** before this phase | yes | folder 11 fixed (IMC agreement review before a contract draft; reviewers see terms), folders 17 and 18 added, runner extended |
| Runner kept environment values as numbers (Postman keeps strings), so "recommended providers" failed | yes | fixed in `run-collection.mjs` |
| `app:mail-check` did not say what SMTP still needs while the transport is `log` | yes | new row naming the missing keys |
| No test that emails omit message text and prices, or that a failing mail job is retried and recorded | yes | `NotificationMailTest` |

## Database
- Migration `2026_10_04_300001_create_public_announcements_table` (new table only). No seeder changed; no demo announcements are seeded (no approved content exists).

## Commands and results
| Command | Result |
| --- | --- |
| `DB_PORT=3307 php artisan test --compact` (MySQL 8.4.9) | **1141 passed (4037 assertions)**, 579 s |
| `DB_PORT=3308 php artisan test --compact` (MariaDB 10.4.32, disposable server: XAMPP binaries, new data directory in a temporary folder, removed afterwards) | **1141 passed (4037 assertions)**, 537 s |
| `composer analyse` | 0 errors |
| `vendor/bin/pint --dirty --format agent` | fixed import style in one new test file; that test re-run: passed |
| `front`: `npx tsc -b`, `npx oxlint`, `npm run build` | clean, clean, built (existing >500 kB chunk warning) |
| Postman, unmodified collection (baseline, scratch DB) | failed in folder 11 (4 assertions) and crashed in folder 16 |
| Postman, updated, `node postman/run-collection.mjs … 400` with the default 60/min limit, freshly seeded scratch DB on MySQL 8.4 | **193 requests, 733 assertions, 0 failed** (91 s) |
| Browser (headless Chrome over CDP, Vite + `php artisan serve` on the scratch DB) | 13/13 UI checks: landing without sidebar; the published announcement from the API, not a draft; still shown after a reload and in a separate browser context; nothing written to `localStorage`; details page from the API; draft → «الإعلان غير موجود»; provider resubmits a rejected listing in the UI and the API stores `pending`; admin page lists stored announcements with their state. (A 14th, setup step answered 409 on the second run because the first, aborted run had already rejected that listing: the correct transition guard.) |
| `php artisan app:mail-check ops@example.test --dry-run` | transport `log`; missing `MAIL_FROM_ADDRESS` (placeholder); still needed for SMTP: `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME` |

All email tests use the `array` transport or a fake failing transport. **No SMTP or Gmail delivery was attempted**: no credentials and no authorized test address exist.

## Engine status
- Declared production engine: **MySQL** (ADR-004), version open (OQ-24; 8.4 LTS recommended).
- The full suite now passes on MySQL 8.4.9 and on MariaDB 10.4.32. MariaDB 10.4 is end-of-life and only the local development default.
- Concurrency and locking are verified by lock-order assertions, not by parallel clients (RK-23).
