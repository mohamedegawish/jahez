# Phase log: ministry administration, approvals and verification (owner brief "Phase 3", 2026-10-04)

Decisions: [ADR-021](../decisions/ADR-021-ministry-administration.md). Open question added: [OQ-46](../open-questions.md#oq-46). Nothing was committed or pushed.

## API changes
- Factory approval (`approval_status`, `approval_reason`, `approval_changed_at`); `POST /factories/{id}/approval`, `POST /factories/{id}/review-request`; requests need an approved factory (`JAHEZ_FACTORY_APPROVAL_REQUIRED`, default on).
- `changes_requested` for providers and factories.
- Per-listing review on `catalog_service_service_provider`; `POST /service-providers/{id}/services/{catalogService}/review`; eligibility, listings, directory and `filter[eligible]` use approved listings only.
- `GET /readiness-assessments`, `GET /readiness-analytics`, `GET /review-summary`.
- Filters and sorts: factories (`approval_status`, `readiness`, `sort`), providers (`category`, `listing_status`, `sort`), listings (`listing_status`, `sort=newest`).
- Notifications: organization registered, review requested, factory approval changed, listing submitted and reviewed, promotion started and ended, assessment recorded.
- `php artisan app:mail-check <address> [--dry-run]`.
- New permissions `factories.approve`, `service_listings.review`; audit events `factory.approval_changed`, `factory.review_requested`, `service_listing.reviewed`.

## Database changes
| Migration | Change |
| --- | --- |
| `2026_10_04_200001` | `factories.approval_status` (default `pending`, indexed), `approval_reason`, `approval_changed_at`; existing rows backfilled `approved` |
| `2026_10_04_200002` | `catalog_service_service_provider.status` (default `pending`, indexed), `status_reason`, `status_changed_at`, `submitted_at`; existing rows backfilled `approved` |

No seeder changed except `LocalDemoSeeder` (demo factories and listings are approved). `ReferenceDataSeeder` was re-run in a test and left 1 version, 5 pillars, 10 questions, 40 choices and the categories 10–17 / 18–25 / 26–33 / 34–40.

## Web client (`front/`)
- Admin sidebar: no «الصفحة الرئيسية»; a «المراجعة والاعتماد» section with badges from `GET /review-summary` (re-read at most every 30 s).
- New pages: `/admin/approvals/providers` and `/:id`, `/admin/approvals/factories` and `/:id`, `/admin/change-requests`, `/admin/requests` (statuses only, OQ-39); `/admin/services` (provider listings in the advertisement-card design, with review actions; the ministry catalog read-only); `/admin/readiness` (analytics, results with answers, versions).
- Dashboard: readiness from the analytics endpoint instead of a 100-factory sample; pending factories and listings.
- Factory portal: approval notice on the dashboard and in the request form; approval status and re-review in settings. Provider portal: listing review status on «خدماتي»; re-review after corrections.

## Commands and results (2026-10-04)
- The local MariaDB 10.4 (port 3306) was **not running** and **does not start**: InnoDB crash recovery stops on a missing tablespace of the test schema (`jahez_testing/readiness_choices.ibd`). It was not repaired (forced recovery touches the shared data directory that also holds the development database). All runs below used the local MySQL 8.4.9 (port 3307).
- A baseline run was started but stopped: it would have read files changed during the run, so its result would not be a baseline. The reference is the Phase 2 log (1045 passed).
- `DB_PORT=3307 php artisan test --compact`, first run after the changes: 1065 passed, 5 failed — the expected contract changes (the re-review message; two lock-order lists now begin with `factories:share`). Updated.
- **Final: `DB_PORT=3307 php artisan test --compact`: 1102 passed (3868 assertions), 275 s.**
- `composer analyse` (Larastan level 8): 0 errors (it found a real bug on the way: a `when()` condition passed `true` instead of the status).
- `vendor/bin/pint --dirty --format agent`: passed.
- `front`: `npx tsc -b` clean, `npx oxlint` clean, `npm run build` succeeded (existing >500 kB chunk warning).
- Browser check (headless Chrome over CDP, Vite on 3000, `php artisan serve` on 8020, a scratch database `jahez_e2e` on 3307, dropped afterwards): 35/35 checks after re-running four pages that the script itself had rate-limited (full page reloads in a row exceed 60 requests/minute; in-app navigation does not). Landing page without `<aside>`; admin sidebar without «الصفحة الرئيسية»; cards, details, listings, analytics and every admin route load without an error; no horizontal overflow at 390 px.
- Mail: `MAIL_MAILER=log`. `php artisan app:mail-check mail-check@example.test` built the message and handed it to the log transport; nothing was delivered. `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME` are empty and `MAIL_FROM_ADDRESS` is the `example.com` placeholder. **Gmail delivery was not tested.**

## Tests added
`AdministrationTest` (30), `LifecycleTest` (10: the whole lifecycle, the eight category boundaries, the seeder re-run), `CheckMailDeliveryTest` (5), policy matrix rows (`approve`, `requestReview`, `reviewListing`), two `AuditTrailTest` cases.

## Not done
- Postman collection not updated (Phase 2 endpoints are also missing from it).
- Not re-run on MariaDB 10.4 (server down, see above).
- No resubmission endpoint for a rejected listing (remove and add the service again).
- Catalog-level activation is not offered: the catalog stays seeder-only (ADR-014).
