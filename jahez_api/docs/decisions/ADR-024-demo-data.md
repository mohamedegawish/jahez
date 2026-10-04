# ADR-024: Demo dataset through the platform's own rules

- **Status:** Accepted (2026-10-04).
- **Decided by:** Owner brief "Dynamic Assessment Administration, Card-Based Listings & Realistic Demo Data" (2026-10-04): synthetic but realistic, interconnected, idempotent demo data that makes every screen meaningful, never in production, never bypassing financial, legal or approval rules. The technical choices below were made during implementation.

## Context

`LocalDemoSeeder` creates two factories and two providers for local development and the Postman collection's negative tests. Most screens (analytics, review queues, cards, negotiations, agreements, notifications) are empty with it. Writing richer data row by row would duplicate workflow logic that lives in controllers (eligibility, approval gates, transitions, audit entries, notifications) and could store states the rules would never produce.

## Decision

1. **A separate, explicit seeder.** `Database\Seeders\DemoDataSeeder`, run only with `php artisan db:seed --class=DemoDataSeeder`. `DatabaseSeeder` never calls it, and the seeder itself refuses to run outside the `local` and `testing` environments (its accounts use the public password `password`). It runs `ReferenceDataSeeder` first, so the exact questionnaire of the source document is in place.
2. **Registration as the registration job does it; everything after through the API.** Each organization is created as `RegisterOrganization` creates one (pending, its member, the `*.registered` audit entry, the IMC notification). Every later step is a real API request, handled in-process by the HTTP kernel as the demo user who would take it: IMC approval decisions and listing reviews, readiness submissions (scored by `ReadinessAssessmentRecorder`), service requests to eligible providers, provider answers, messages, offer versions, offer acceptance, IMC agreement reviews, a contract draft, promotions, announcements and legal change requests. A step the rules refuse stops the seeder; nothing is written around a rule.
3. **No money, no binding contract.** No financial policy, invoice or payment is created, so invoicing stays blocked (409 `policy_not_configured`, OQ-15/16). The one contract is a draft on the agreement IMC approved, which the existing rules allow; drafts are never binding (OQ-17). No document or logo file is fabricated.
4. **Create-only and idempotent.** Every demo account is `…@demo.jahez.test`. An organization whose member account exists is skipped with everything scripted for it; requests are matched by factory and title, promotions by provider, service and headline, announcements by title, change requests by their note, readiness submissions by an `Idempotency-Key`. Nothing existing is updated, so changes made in the UI survive a re-run, and real records are never read or modified.
5. **A realistic timeline.** The clock is moved for each step (restored afterwards) so registrations, assessments and negotiations spread over about nine months. Only for the seeding process, the generic per-user API rate limit is raised (moving the clock back and forth would otherwise count months of activity as one minute) and email notifications are not queued; in-app notifications are stored as in production. Demo users have email notifications off.
6. **Dataset.** 16 factories (four sectors, sixteen governorates; 11 approved, 2 pending, 1 corrections requested, 1 rejected, 1 suspended), 8 providers (5 approved, 1 pending, 1 corrections requested, 1 rejected) with 20 listings (12 approved, 4 pending, 2 rejected, 2 suspended), 16 assessments from 13 factories covering the four levels and the boundary totals 10, 17, 18, 25, 26, 33, 34 and 40, 10 service requests with 6 offer versions, 11 messages and 4 agreements (2 awaiting IMC, 1 approved with a contract draft, 1 rejected), 3 promotions (running, scheduled, ended), 4 announcements (2 live, 1 draft, 1 ended) and 4 legal change requests (pending, approved, rejected).

## Consequences

- IMC notifications produced by the demo steps also reach the other IMC accounts of the database (for example the local `test@example.com`), as in production. Seed an isolated database.
- A change to a workflow rule can make a scripted step fail; `DemoDataSeederTest` runs the seeder twice in the test suite, so the failure shows there first.
- The marker is the email domain; display text is ordinary Arabic, and registration numbers use a visible `DEMO-` prefix so they cannot be mistaken for real ones.
