# Phase log: cumulative readiness levels, plan-based progression and hidden scores (owner request, 2026-10-06)

**Request (owner, Arabic, 2026-10-06):**
- a factory that completes the assessment sees its level, not its score;
- a factory that finishes the services of, for example, level 1 gets level 2 opened;
- a factory at an advanced level from the start sees the providers of its level and every lower one.

**Decision record:** [ADR-026](../decisions/ADR-026-cumulative-levels-and-progression.md).

**Nothing was committed, pushed or deployed.**
- The development database `jahez` on MariaDB (port 3306) was not touched.
- The test suite ran on MySQL 8.4 (port 3307), against `jahez_testing`.

## 1. Owner decisions taken in this session (asked during planning)

| Question | Answer |
| --- | --- |
| When is a service "finished"? | When IMC records the plan item as completed (the only completion record, OQ-52) |
| When is a level finished? | When the services of that level in the factory's plan are completed |
| Which level does the factory see? | The highest opened level |
| Do IMC administrators keep the score? | Yes; only the factory loses it |

## 2. API changes

- **Cumulative levels:** `ServiceEligibility` uses the factory's level and every level below it on every path.
- **The factory's level:**
  - the highest of the assessed category and `readiness_level_unlocks`;
  - shown as `readiness_level` on the factory, and in `readiness.level` / `assessed_level` / `unlocked_by` on the eligibility endpoints and the listings meta;
  - `current_level` on the IMC plan detail.
- **Progression:** `App\Readiness\LevelProgression`, called from `TransformationPlanItemController::act()` (complete, cancel) and `TransformationPlanDraft::publish()`. It:
  - inserts the unlock;
  - audits `factory.readiness_level_unlocked`;
  - notifies `readiness_level_unlocked`.
- **Scores for `assessments.view_any` only:** assessments, categories, the questionnaire (points and ranges), `current_readiness`, the eligibility summary and the listings meta.
- **Migration:** `2026_10_07_100009_create_readiness_level_unlocks_table`.

## 3. Web client

- **Factory screens show the level name and badge, never a number:**
  - assessment result and history;
  - dashboard KPI;
  - profile card;
  - services banner;
  - onboarding step.
- **Plan note:** the result page says when the plan opened a higher level.
- **Copy:** the catalog, service details and level administration texts describe cumulative levels and progression.
- **Admin screens keep the scores:**
  - the factory review page also shows an opened level;
  - the plan detail shows the factory's current level;
  - the questionnaire preview notes that the total is IMC-only.

## 4. Commands and results

| Command | Result |
| --- | --- |
| `DB_PORT=3307 php artisan test --compact` (first full run) | 1368 passed, 10 failed: 9 `LifecycleTest` cases and 1 `ReadinessQuestionnaireVersionTest` case still read the score as a factory member. Updated to the new contract |
| `DB_PORT=3307 php artisan test --compact` (final) | **1378 passed (5701 assertions)**, 594 s |
| `composer analyse` (Larastan 8) | No errors, after replacing a `get()->isNotEmpty()` lock read with `first() !== null` |
| `vendor/bin/pint --dirty --format agent` | passed |
| `npm run build` (front: `tsc -b` + vite) | built; only the existing chunk-size warning |
| `npm run lint` / `npm test` (front) | clean / 27 passed |
| Postman: fresh scratch DB `jahez_postman_adr026` on MySQL 8.4 (`migrate` + `db:seed`); `php -S` with `MAIL_MAILER=log`; `node postman/run-collection.mjs … 400` | **955 assertions passed, 0 failed** |

The scratch server was confirmed to serve the scratch database before the run (user count 7, against 10 on the development database), then stopped.

## 5. Tests added or changed

- **New:**
  - `tests/Feature/Api/V1/ReadinessLevelProgressionTest.php` (8 tests): cumulative availability on every path; opening the next level; a cancelled item; no item at the level; publishing a version without the last open item; permanence and reassessments; Smart; append-only rows;
  - one `AuditTrailTest` case.
- **Changed to the owner's new contract, with no assertion weakened.** Scores read as a factory member are now asserted absent for the member, and checked as IMC or from the stored row. Files: `ReadinessAssessmentTest`, `ReadinessHistoryTest`, `ReadinessQuestionnaireTest`, `FactoryTest`, `LifecycleTest` and `ServiceEligibilityTest`.
- **New assertion:** `ServiceEligibilityTest` now expects the Basic service to remain after a move to Advanced.
- **Postman:** assertions in folders 04a and 07 (Readiness Assessment) now check that a factory member gets no score, ranges or points, and gets `readiness_level`.

## 6. Not done or still open

- **OQ-56:** whether an opened level can be closed again (lower reassessment, IMC correction). Interim: never.
- **OQ-52:** who reports completion and on what evidence; it now also decides when a factory moves up.
- **The plan review still blocks next-level services until the level opens.** IMC adds them in a new version.
