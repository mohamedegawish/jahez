# Phase log: provider and factory portals (owner brief "Phase 2", 2026-10-04)

Decisions: [ADR-020](../decisions/ADR-020-provider-and-factory-portals.md). Open questions added: OQ-43, OQ-44, OQ-45.

## Database changes
| Migration | Change |
| --- | --- |
| `2026_10_04_100001` | `agreement_reviews` (one append-only IMC decision per agreement) |
| `2026_10_04_100002` | `notifications` (database channel); `users.email_notifications` (default true) |
| `2026_10_04_100003` | `provider_request_reads` (per-user read mark per thread) |
| `2026_10_04_100004` | `service_promotions` |
| `2026_10_04_100005` | `factory_profile_change_requests`; `organization_documents.factory_profile_change_request_id` |

## Fixes to Phase 1 code found on the way
- `RegistrationController` dispatched `RegisterOrganization` without the registration id the job now requires (Larastan: 10 errors; public registration would fail at dispatch). It now passes a UUID and deletes staged uploads when staging or queuing fails (the two failing `RegistrationTest` cases). One stale assertion was updated to allow the `registration_id` the job records.

## Commands and results (2026-10-04)
- `php artisan test --compact`: baseline before the work 13 failed / 983 passed; after: **1045 passed, 0 failed** (3558 assertions, 392 s, MariaDB 10.4). Not re-run on MySQL 8.4 (port 3307).
- `composer analyse` (Larastan level 8): **0 errors**.
- `vendor/bin/pint --dirty --format agent`: passed.
- Web client: `npx tsc -b` clean, `npx oxlint` clean, `npm run build` succeeded (existing >500 kB chunk warning).
- Live smoke against `php artisan serve` with the demo accounts: factory listings → request to provider P → provider Q gets 404 → provider notified → accept → message, unread 1 → read → offer → factory accepts → agreement `pending` → contract draft 409 → IMC approves → contract draft created → factory notifications link to the request and agreement. Report reflected the new agreement.
- Mail: `MAIL_MAILER=log` locally. `php artisan queue:work --stop-when-empty` processed 9 `PlatformEventMail` jobs, 0 failed; the 9 messages were written to `storage/logs/laravel.log` with no message text or price. **No real delivery was tested**: no SMTP/API mail provider, credentials or DNS (SPF/DKIM) are configured.

## Not done
- Postman collection not updated with the new endpoints.
- Concurrency of `POST /agreements/{id}/review` is guarded by the row lock and the unique key; not exercised with parallel clients.
