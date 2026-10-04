# Phase 2: Data Model and Migrations

**Date:** 2026-10-02 · **Branch:** `phase/02-data-model` (from `phase/01-foundation` @ `fc7b89d`) · **Result:** exit gate **PASS** for the approved scope (§7)

## 1. Scope

Owner instruction: "ok, and complete" (after the Phase 1 report). This was taken as:
- (a) commit Phase 1, done as `fc7b89d`;
- (b) approval **A6**, build the DOC reference data;
- (c) completing Phase 2 within what can be built without inventing business rules.

**A7 / [OQ-23](../open-questions.md#oq-23) (Arabic-only or bilingual) was not answered**, so the documented interim applies ([ADR-010](../decisions/ADR-010-reference-data.md)).

| Delivered | Where |
| --- | --- |
| 7 tables: `pathways`, `pathway_levels`, `pathway_scope_items`, `level_provider_requirements`, `maturity_tiers`, `sectors`, `evaluation_criteria` | `database/migrations/2026_10_02_18000{1..7}_*.php` |
| 7 models with typed relationships and casts | `app/Models/` |
| Idempotent, transactional, production-safe seeders with DOC text and page references | `database/seeders/{ReferenceData,Sector,Pathway,MaturityTier,EvaluationCriterion}Seeder.php` |
| `DatabaseSeeder` hardened: calls `ReferenceDataSeeder`; creates the known-password test user only in local/testing, and only once | `database/seeders/DatabaseSeeder.php` |
| 15 database tests | `tests/Feature/Database/` |
| Glossary; ADR-010; OQ-32; updates to the data model, traceability, plan, architecture, testing strategy, skills and agent guidelines | `docs/`, `.ai/guidelines/jahez.md` |

**Not in this phase (blocked or carried forward):**
- Organization tables: factories, providers, memberships (decision D4, fields [OQ-19](../open-questions.md#oq-19)/[OQ-20](../open-questions.md#oq-20)).
- The service catalog ([OQ-01](../open-questions.md#oq-01), workbook missing).
- Assessments ([OQ-06](../open-questions.md#oq-06)–[OQ-12](../open-questions.md#oq-12)).
- Engagements, contracts and finance ([OQ-03](../open-questions.md#oq-03), [OQ-15](../open-questions.md#oq-15)–[OQ-17](../open-questions.md#oq-17)).
- No API endpoints were added. Exposing reference data needs the authorization model from Phase 3.

## 2. Decisions

| Decision | Reason | Record |
| --- | --- | --- |
| Relational reference tables with a stable `code`, not PHP enums | Owner-correctable display text; FK relationships | ADR-010 |
| Separate `pathways` table instead of a `pathway` string on levels | DOC structure: 2 pathways, the second with 3 levels, each pathway with its own target group | data-model.md |
| `*_ar` authoritative; `*_en` nullable and filled only from English printed in DOC | OQ-23 unanswered; no invented translations | ADR-010 |
| Arabic transcribed from the PDF **page images** | The PDF text layer is garbled by RTL extraction | [OQ-32](../open-questions.md#oq-32) (proofreading) |
| `maturity_tiers.score_min/score_max` NULL and **not in `$fillable`** | No approved thresholds ([OQ-07](../open-questions.md#oq-07)). Mass-assigning them throws in non-production (`shouldBeStrict`). | ADR-010, test |
| Evaluation criteria stored as **version 1** with UNIQUE (version, code) | A future weight change must not rewrite historical evaluations | ADR-010 |
| `restrictOnDelete` on all reference FKs | Reference rows in use must never vanish | data-model.md §5 |
| No factories for reference models | Tests must use the real source rows | testing/strategy.md |
| Migration files renamed to explicit sequence `18000{1..7}` | `make:model -m` produced identical timestamps, which would have run `pathway_levels` before `pathways` | — |
| Concurrency tests **not applicable** | Reference data is written only by seeders, never by concurrent requests | — |
| `EXPLAIN` analysis **not applicable** | No application queries exist yet | — |

## 3. Changed files

**New (24):**
- Models (7): `app/Models/{Pathway,PathwayLevel,PathwayScopeItem,LevelProviderRequirement,MaturityTier,Sector,EvaluationCriterion}.php`
- Migrations (7): `database/migrations/2026_10_02_180001_create_pathways_table.php` … `180007_create_evaluation_criteria_table.php`
- Seeders (5): `database/seeders/{ReferenceData,Sector,Pathway,MaturityTier,EvaluationCriterion}Seeder.php`
- Tests (2): `tests/Feature/Database/{ReferenceDataSeederTest,ReferenceDataConstraintsTest}.php`
- Docs (3): `docs/glossary.md`, `docs/decisions/ADR-010-reference-data.md`, this file

**Modified (11):** `.ai/guidelines/jahez.md`, `AGENTS.md` (regenerated: +2 lines, 0 removed), `database/seeders/DatabaseSeeder.php`, `docs/{README,architecture,data-model,engineering-skills,implementation-plan,open-questions,requirements-traceability}.md`, `docs/testing/strategy.md`.

**Outside git:** the dev database `jahez` was migrated (7 tables) and seeded with reference data. A scratch database `jahez_migration_check` was created and dropped.

## 4. Test and check results

### 4.1 Test suite (final)

| Command | Result |
| --- | --- |
| `php artisan test --compact` | **50 passed** (119 assertions), 0 failed, 0 skipped |
| `php artisan test --parallel --processes=4` | **50 passed** (119 assertions) |
| `php artisan test --compact tests/Feature/Database` (first run) | 15 passed (28 assertions). Passed on the first run. |

New tests (15):

| File | Test | Proves |
| --- | --- | --- |
| `ReferenceDataSeederTest` | complete set | 4 sectors, 2 pathways, 4 levels, 25 scope items, 9 requirements, 4 tiers, 5 criteria |
| | sectors | Exact Arabic names; no English invented |
| | levels | Pathway membership, Arabic and English names, item and requirement counts per level (6/0, 6/3, 7/3, 6/3) |
| | tiers | Names, bands, tier → approved level mapping; thresholds NULL |
| | criteria | Weights 30/25/20/15/10; total `100.00` |
| | idempotent | Re-run keeps identical row IDs in all 7 tables |
| | drift repair | Re-run restores edited text and removes a surplus scope item |
| | production guard | `db:seed --force` in `production` seeds reference data but **no** `test@example.com` user |
| | test user once | Repeated local seeding creates one test user |
| `ReferenceDataConstraintsTest` | duplicate sector code | `UniqueConstraintViolationException` |
| | FK restrict | Deleting a level with dependents throws; the level still exists |
| | duplicate scope position | `UniqueConstraintViolationException` |
| | duplicate criterion within a version | `UniqueConstraintViolationException` |
| | criterion code in a new version | Allowed (versions 1 and 2 coexist) |
| | thresholds not mass-assignable | `MassAssignmentException`; `score_min` stays NULL |

### 4.2 Mutation checks
- Changing the `DatabaseSeeder` environment guard to `if (true)` made **"never creates the known-password test user outside local and testing"** fail.
- Disabling surplus deletion in `PathwaySeeder` made **"restores changed source text and removes surplus items"** fail.
- Both files were restored byte-for-byte.

### 4.3 Static analysis and formatting

| Command | Result |
| --- | --- |
| `composer analyse` (level 8) | First: 1 error at `DatabaseSeeder.php:25` (`factory()->raw()` returns `array<int\|string, mixed>`). Fixed by restructuring to `doesntExist()` + `factory()->create()`, with no cast or ignore. Final: **0 errors, 47 files**. |
| `vendor/bin/pint --dirty --format agent` | `passed` |

### 4.4 Migrations and seeding (MySQL protocol, local MariaDB 10.4.32)

| Step | Result |
| --- | --- |
| Scratch DB: `migrate` | 11 migrations DONE, including the 7 new ones in dependency order |
| `db:seed --class=ReferenceDataSeeder --force` ×2 | Counts after each run: `4 2 4 25 9 4 5` (sectors, pathways, levels, scope items, requirements, tiers, criteria). Identical. |
| `migrate:rollback` with seeded data present | All 11 rolled back (single batch), reverse FK order without errors; only `migrations` left |
| `migrate` + `migrate:fresh --seed` | DONE; counts `4 2 4 25 9 4 5` |
| Scratch DB dropped | Yes |
| Dev DB `jahez`: `migrate` + `db:seed --class=ReferenceDataSeeder --force` | 7 tables created and seeded; `migrate:status` shows 11 Ran |

### 4.5 Code review
The `code-review` skill (medium) reviewed the Phase 2 diff and reported **no findings** (details in [engineering-skills.md](../engineering-skills.md#phase-2-usage)).

## 5. Source fidelity

- **Counts** match DOC exactly:
  - 4 sectors (p.2) and 2 pathways (p.3–4).
  - Foundational scope: 2 groups × 3 items (p.3). Basic DX 6, Advanced DX 7, Smart DX 6 (p.4–5).
  - 3 provider requirements per DX level (p.4–5).
  - 4 tiers (p.6) and 5 criteria with weights summing to 100% (p.8).
- **Text:** transcribed from the page images. Only whitespace around punctuation and the placement of parentheses in mixed Arabic/Latin runs were normalised. Source spellings were kept (for example "الي", "رقمنه", "الهياكل التنظيمي").
- **Uncertain spot:** the Foundation tier's approved-path cell (p.6) renders with unbalanced parentheses. It is stored as `المسار الأول: التمكين التأسيسي (Lean, 5S, BPR والهيكلة الإدارية) تحسين البنية التحتية.`, which is the most plausible reading. **Owner proofreading is required ([OQ-32](../open-questions.md#oq-32)).**
- **Nothing beyond DOC:** the catalog, thresholds, scoring scale and revenue-share rules are absent. The "5%–20%" figure exists only inside the criterion's descriptive text, exactly as printed.

## 6. Security and performance notes

- `DatabaseSeeder` no longer creates the known-password `test@example.com` account outside local/testing. Before this change, running `db:seed --force` in production would have created it.
- Reference data has no write path except seeders, and no API exposure yet.
- Performance: nothing was measured. The reference tables are tiny (≤ 25 rows) and indexed by their unique keys.

## 7. Exit gate

| Criterion (master prompt, Phase 2) | Status | Evidence |
| --- | --- | --- |
| ERD/data model finalised from source-backed requirements | ✅ for the approved scope | [data-model.md](../data-model.md) §1–§4 |
| Tables only where in scope **and approved** | ✅ | 7 reference tables (A6); everything else documented as blocked |
| Constraints and indexes justified | ✅ | UNIQUE codes and positions (integrity), FK RESTRICT (no orphans), no speculative indexes |
| Idempotent reference-data seeders | ✅ | §4.1 idempotent and drift tests; §4.4 double seed |
| Fresh migration on a clean DB | ✅ | §4.4 |
| Rollback checked | ✅ | §4.4 (with data present) |
| FK, uniqueness, nullability and deletion tests | ✅ | §4.1 constraints file; thresholds NULL |
| Seeders repeatable without duplicates | ✅ | §4.1 and §4.4 |
| Concurrency tests where applicable | ➖ Not applicable | Seeder-only writes (§2) |
| **Source catalog data not fabricated** | ✅ | No catalog rows; English only from DOC; thresholds unset (§5) |

**Gate: PASS for the approved scope.** The Arabic text is pending owner proofreading (OQ-32). That does not block later phases, but it must happen before the text is shown to users.

## 8. Unresolved issues and next phase

- **Commit:** Phase 2 changes are **uncommitted** on `phase/02-data-model`, ready for review with `git diff phase/01-foundation`.
- **Phase 3 (authentication and authorization)** needs decisions **D1–D4** ([implementation-plan.md §2](../implementation-plan.md#decisions-for-phase-3-authentication-and-authorization-applied)). Each has a recommended default.
- Still the most valuable owner inputs: the workbook ([OQ-01](../open-questions.md#oq-01)), the operating model ([OQ-03](../open-questions.md#oq-03)), the readiness index and thresholds ([OQ-06](../open-questions.md#oq-06)/[OQ-07](../open-questions.md#oq-07)), and proofreading ([OQ-32](../open-questions.md#oq-32)).
