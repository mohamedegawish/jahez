# Phase log: assessment administration, card listings and demo data (owner brief, 2026-10-04)

Brief: "Jahez — Dynamic Assessment Administration, Card-Based Listings & Realistic Demo Data". Decisions: [ADR-018 addendum 2](../decisions/ADR-018-digital-readiness-assessment.md#addendum-2-2026-10-04-locked-shape-editable-roadmap-and-history), [ADR-021 amendment](../decisions/ADR-021-ministry-administration.md), [ADR-024](../decisions/ADR-024-demo-data.md). Open question added: [OQ-51](../open-questions.md#oq-51). Approval row: A19 in the implementation plan.

**Nothing was committed, pushed or deployed. No database was reset, truncated or dropped.** The broken local MariaDB data directory (port 3306) and the development database `jahez` were not touched; every run used the local MySQL 8.4.9 on port 3307.

## 1. Inspected first

- ADR-010, ADR-014, ADR-018 (and its addendum), ADR-020 to ADR-023; the readiness module (tables, models, version API, analytics, seeder and its source test); the factory and service-listing endpoints and resources; `LocalDemoSeeder`; the marketplace, agreement, contract and invoice controllers; the web client's admin pages, card components and API layer.
- What already existed: the exact questionnaire (version 1) seeded from `docs/إطار تقييم مستوى الجاهزية الرقمية.docx` and verified against it by `ReadinessAssessmentSourceTest`; the draft/publish API; results and analytics endpoints; listings already shown as cards.
- The brief names the attachment `إطار تقييم مستوى الجاهزية الرقمية(1).docx`; the repository holds one copy without "(1)", which was used. Its text was extracted again and compared: it matches the seeded version 1.
- Where the brief and the source differ, the source wins: the Basic label is «مبتدئ / رقمنة أساسية», as in the document.

## 2. Owner decisions taken in this session

1. **Lock the source shape** (asked during planning): every version keeps 5 pillars × 2 questions × 4 choices worth 1–4, so totals are always 10–40. Wording, labels, order, level texts and bounds, and recommendations stay editable.
2. **No correction workflow** for stored assessments (asked during planning): recorded as OQ-51.

## 3. API changes

| Area | Change |
| --- | --- |
| Scoring | `App\Readiness\ReadinessAssessmentRecorder`: the single place that scores, classifies and stores an assessment (moved out of the controller unchanged; used by the API and the demo seeder). Refuses incomplete answer sets and non-current versions. |
| History | Answers store `question_text_ar`, `choice_label_ar`, `choice_text_ar` (migration `2026_10_06_100001`, with backfill); the resource prefers the snapshot. |
| Versions | `created_by_user_id`, `updated_by_user_id`, `published_by_user_id` (migration `2026_10_06_100002`), returned as `created_by`, `updated_by`, `published_by` with `updated_at`. |
| Editor | `PUT /readiness-questionnaires/{id}`: locked shape (`App\Readiness\QuestionnaireShape`), labels distinct, points {1,2,3,4}; optional `categories.*.recommendations` mapped to existing catalog codes. `definitionProblems()` mirrors the rules, so `publish` re-checks them and requires a recommendation per level. |
| Results | `GET /readiness-assessments`: `filter[score_min]`, `filter[score_max]`, `filter[factory]`. |
| Factory list | `GET /factories` returns `FactorySummaryResource` (no legal name, contact details, address, registration numbers or non-logo documents; adds `logo`, `profile_completion` by field name, `service_requests_count`). `GET /factories/{id}` is unchanged. |

No permission was added or changed. Editing and publishing still need `readiness_questionnaires.manage`; results need `assessments.view_any`; factory decisions `factories.approve`; listing decisions `service_listings.review`; promotions `promotions.manage`.

## 4. Web client (`front/`)

- «إدارة تقييم الجاهزية الرقمية» (`/admin/readiness`, sidebar renamed) with seven tabs: نظرة عامة · محاور التقييم · الأسئلة والاختيارات · مستويات الجاهزية · التوصيات وخارطة الطريق · نتائج تقييم المصانع · سجل الإصدارات والإحصائيات. A shared workspace edits the draft (or shows the current version read-only with «إنشاء مسودة»), with save, discard, a factory-view preview (the same question component the factory page uses), a change review against the current version before publishing, and delete. Add/remove buttons for questions and choices are gone; reordering stays. `pages/admin/ReadinessQuestionnaires.tsx` was removed: its editor moved into the tabs.
- Results: score range, factory and the existing filters; the answer modal shows the stored answer text and the score calculation.
- Factories (`/admin/factories`): cards (`FactoryCard`) with logo or fallback, sectors, place, size, approval and readiness badges, request count, registration and assessment dates; actions: full profile, review page (documents, change requests, history), assessment results, approval decisions (by permission). Filters: sector, size, approval status, readiness level, sort; server pagination; loading, empty and error states.
- Services (`/admin/services`): listing cards gained a details modal with the listing's approval history (audit log), sort, a promoted filter, a provider-status filter, and a «ترويج» action on approved listings of approved providers (the promotion modal now offers approved listings only). The ministry catalog tab is read-only cards grouped by category.
- `src/lib/readinessDefinition.ts` (shape and range checks, change diff, preview classification) with `tests/readinessDefinition.test.ts`.

## 5. Demo data

`php artisan db:seed --class=DemoDataSeeder` (local/testing only, never from `DatabaseSeeder`), on an isolated database:

| Entity | Seeded |
| --- | --- |
| Accounts | 25 on `@demo.jahez.test` (1 IMC reviewer, 16 factory members, 8 provider members), password `password`, email notifications off |
| Factories | 16 in 4 sectors and 16 governorates: 11 approved, 2 pending, 1 corrections requested, 1 rejected, 1 suspended |
| Providers / listings | 8 (5 approved, 1 pending, 1 corrections requested, 1 rejected) / 20 (12 approved, 4 pending, 2 rejected, 2 suspended) |
| Readiness | 16 assessments of 13 factories (3 not assessed), totals 10, 12, 14, 17, 18, 21, 22, 25, 26, 27, 29, 30, 33, 34, 37, 40; current levels: 3 ما قبل الأتمتة, 3 مبتدئ, 4 متقدم, 3 ذكي ومبتكر |
| Marketplace | 10 requests (4 awarded, 5 open, 1 cancelled), 10 threads (4 agreed, 2 negotiating, 1 pending, 1 declined, 1 withdrawn, 1 closed), 11 messages, 6 offer versions |
| Agreements | 4: 2 awaiting IMC review, 1 approved (with one non-binding contract draft), 1 rejected |
| Money | none: 0 invoices, 0 payments, 0 financial policies; invoicing answers 409 `policy_not_configured` |
| Promotions / announcements | 3 (running, scheduled, ended) / 4 (2 live, 1 draft, 1 ended) |
| Change requests | factory: 1 pending, 1 approved; provider: 1 pending, 1 rejected |
| Produced by the workflow | 237 in-app notifications and 135 audit entries in `jahez_demo` (with the local seeder's accounts present) |

## 6. Commands and results (2026-10-04)

| Command | Result |
| --- | --- |
| `DB_PORT=3307 php artisan test --compact` (first full run, after the API changes) | **1291 passed (4908 assertions)**, 447 s |
| `DB_PORT=3307 php artisan test --compact` (final full run) | **1291 passed (4909 assertions)**, 518 s |
| Targeted runs while working | readiness, factory, portal, administration and audit files: 195 passed; readiness, models and seeder files: 120 passed; `DemoDataSeederTest`: 4 passed (102 assertions) |
| `composer analyse` (Larastan level 8) | 0 errors (12 found in the new code on the way and fixed: list types, an empty-array guard, an unneeded access token) |
| `vendor/bin/pint --dirty --format agent` | passed |
| `front`: `npx tsc -b`, `npx oxlint`, `npm test`, `npm run build` | clean; clean (4 warnings in the new code fixed: constants split from components, state adjusted during render instead of in an effect); 18/18 tests; built (existing > 500 kB chunk warning) |
| New scratch database `jahez_demo` (3307): `migrate`, `migrate:rollback --step=2`, `migrate` | the two new migrations roll back and re-apply |
| `db:seed`, then `db:seed --class=DemoDataSeeder` twice | every table count identical after the second run; 0 queued jobs |
| Postman, `node postman/run-collection.mjs … http://127.0.0.1:8030 400` on the new scratch database `jahez_postman_run3` | **222 requests, 841 assertions passed, 0 failed**. The first run (on `jahez_postman_run2`) failed one new assertion that used a chain the runner lacks; rewritten, then the whole collection re-run on a fresh database |
| Browser: headless Chrome over CDP, Vite on port 3100 (port 3000 was in use by another server, left alone) and the API on 8020 against `jahez_demo` | **29/29 checks**: landing (2 live announcements, no draft or ended one); factory cards (15 per page of 18, decision buttons, approval/readiness/sort filters, empty state); listing cards (pending queue, details with history, promoted filter), 42 catalog cards; all seven readiness tabs; results for one factory with the calculation (27 = sum of its answers) and a score-range filter; draft created, edited, saved, reviewed, previewed and deleted; no horizontal overflow at 390 px on six admin views; factory portal (result 40, requests, agreement with its contract draft, invoicing blocked state, notifications); provider portal (requests, 4 listings); no uncaught page exception |

## 7. Tests added or changed

- `ReadinessQuestionnaireVersionTest`: the 0–3 points case became a reordering within 1–4 (contract change, owner decision); 11 shape cases, roadmap editing, 3 roadmap refusals, actors, 3 publish re-checks.
- `ReadinessHistoryTest` (new): text snapshot after an in-place correction, old results unchanged after a reworded and re-banded version is published, score and factory filters, cross-factory 404, the recorder's rules.
- `FactoryTest`: the list is a summary without legal or contact values, with counts and completion; details keep them.
- `PortalWorkflowTest`: promotions never surface a pending or rejected listing; listing cards carry no provider legal or contact detail.
- `DemoDataSeederTest` (new): refuses in production even with `--force`, not called by `DatabaseSeeder`, the full dataset, every total equal to its answers and its category to the ranges, all four levels and boundaries, no money or policy, one draft contract, idempotency with a UI change preserved, public feed, invoicing blocked.

## 8. Not done or still open

- **OQ-51:** no correction workflow (owner decision).
- **Money and contracts:** invoices, payments and binding contracts remain blocked by OQ-15/16/17; the demo shows the blocked state.
- **Promotion creation** still accepts any listing the provider lists (visibility to factories requires approved listing and provider, tested); the UI now offers approved listings only. Making the API refuse unapproved listings would be a contract change and was not made.
- **Found, not changed (out of scope):** on `/admin/providers` the «مراجعة» button links to `/admin/approvals?provider=ID`, which redirects to the provider queue and drops the id.
- Scratch databases created on 3307 and left in place: `jahez_demo` (for browsing the demo), `jahez_postman_run2`, `jahez_postman_run3`. Drop them when no longer needed.
- Not re-run on MariaDB 10.4 (the local server does not start).

## 9. Follow-up (2026-10-04, after delivery)

- **500 on `GET /readiness-questionnaires` in the development app:** the development database `jahez` (MariaDB, port 3306, running normally again) had not run the two new migrations, so `readiness_questionnaires.created_by_user_id` did not exist. After a `mysqldump` backup, `php artisan migrate` applied `2026_10_06_100001` and `2026_10_06_100002`; the readiness endpoints answer 200.
- **A public registration did not appear in the system:** registration is processed by the queued `RegisterOrganization` job, and no queue worker was running, so the provider's registration waited in `jobs`. One `php artisan queue:work --stop-when-empty` created it (pending, its services pending review, its member invited, IMC notified). The same path was checked end to end for a factory and a provider on the scratch database `jahez_demo`: both are listed as pending for IMC once the queue runs. `RegistrationTest` and `LifecycleTest`: 34 passed. Keep `php artisan queue:work` running next to the server (README, step 5).
- **Providers page as cards (owner request):** `/admin/providers` now shows `ProviderAdminCard`s (logo or fallback, sectors, place, experience, approval status, approved/total listings, pending listings, registration date, reason when not approved) with server filters, a sort, the `changes_requested` status, approval decisions by `service_providers.approve`, and links to the review page and the provider's services. The «مراجعة» link that dropped the provider id (section 8) is fixed: cards and the create flow open `/admin/approvals/providers/{id}`. `tsc`, `oxlint`, `npm test` (18/18) and the build pass; headless Chrome on `jahez_demo`: 6/6 (11 cards, no table, decision buttons, status filter, the review link opens that provider, no overflow at 390 px).
- **Factory registration "not working" (again the queue):** the registration of «مصنع السويدي» and 8 notification emails were waiting in `jobs` because no worker was running; `php artisan queue:work --stop-when-empty` created the factory (pending), invited its member and sent the emails to the log transport.
- **The quiz for a new factory:** the factory sidebar gains «تقييم الجاهزية الرقمية» (`/factory/assessment`); the dashboard's onboarding steps already pointed to it. End to end on `jahez_demo` in headless Chrome, 5/5: registration → queue → the emailed invitation link opens the set-password page → sign-in → the dashboard shows «بدء التقييم» and the sidebar item → 10 questions with 4 choices each → result 30 «متقدم» stored as `current_readiness`, approval still pending.
- **Emails:** `MAIL_MAILER=log` writes every email to `storage/logs/laravel.log`; nothing is delivered. `php artisan app:mail-check … --dry-run` lists `MAIL_FROM_ADDRESS`, `MAIL_HOST` and `MAIL_SCHEME` as missing for SMTP. Real delivery needs the owner's SMTP credentials (not available to the assistant).
- **Password of the provider account `businessgawish@gmail.com`:** set at the owner's request, as the reset flow does (password checked against the app's rules, email marked verified, tokens and pending reset links removed, audit entry `auth.password_reset_completed` without the password).
