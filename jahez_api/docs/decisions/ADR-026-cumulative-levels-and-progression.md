# ADR-026: Cumulative readiness levels, plan-based progression and hidden scores

- **Status:** Accepted (2026-10-06).
- **Supersedes in part:**
  - [ADR-025](ADR-025-readiness-eligibility-and-transformation-plans.md) §2: the exact-level rule;
  - [ADR-018](ADR-018-digital-readiness-assessment.md) §5: for eligibility and display, the factory's level is no longer only its latest assessment.
- **Decided by:** Owner request (2026-10-06):
  - a factory that completes the assessment sees its level, never its score;
  - a factory at a higher level also sees the providers of every lower level;
  - finishing a level's services opens the next level.

  Four choices were made by the owner in the same session (questions asked during planning). They are listed under Decision.

## Context

ADR-025 made a service available to a factory only when IMC mapped it to exactly the factory's level:
- **Moving up lost services:** a factory moving from Basic to Advanced lost the Basic services.
- **No other way up:** the level changed only through a new self-assessment.

The API sent every score to the factory's members:
- the total;
- the score ranges;
- the pillar scores;
- the points of each answer and of each questionnaire choice.

## Decision

### 1. Levels are cumulative

**Ordering.** The four category codes are ordered `b4_automation` < `basic` < `advanced` < `smart` (`ReadinessCategoryCode::rank()`, `atOrBelow()`, `next()`).

**Availability.** A service is available to a factory when an active `readiness_level_services` row exists for the factory's level **or any level below it**. `ServiceEligibility` remains the only source, so every factory path follows the rule:
- the catalog and categories;
- listings;
- the directory;
- eligibility;
- request creation and adding providers, re-checked in the transaction;
- the plan review.

**Unchanged:** a factory without an assessment still gets nothing, and IMC still adds each service by hand. Mapping a service to the lowest level that needs it is enough.

### 2. The factory's level

**Definition.** The factory's level is the highest of:
- its current assessment's category (ADR-018), and
- the levels it opened through its plan (below).

**Display.** It is the level the factory sees as its own (owner decision: "the highest unlocked level"):
- `FactoryResource.readiness_level`: `code`, `name_ar`, `name_en`, `unlocked_by` (`assessment` | `plan_completion`), `unlocked_at`;
- `readiness.level` on the eligibility endpoints, plus `assessed_level`.

**Unchanged:** the assessment, its category and `current_readiness` are not rewritten. Readiness recommendations still follow the assessed category.

### 3. Progression through the transformation plan

**When a service is finished** (owner decision): IMC records the plan item as `completed`. This is the only completion record in the platform (OQ-52 interim).

**When a level is finished** (owner decision): every item that meets all three of the following has been completed:
- it is in the **published version of the factory's plan**;
- its service belongs to the level, a service belonging to the lowest level it is actively available to;
- it is not cancelled.

**Refinements:**
- at least one such item must exist, so a level with no plan item opens nothing;
- items of lower levels do not count;
- a cancelled item does not hold the level back.

**Opening the next level.** `App\Readiness\LevelProgression` checks this inside the transaction of the IMC actions that can finish a level, after the plan row is locked:
- closing an item (complete or cancel);
- publishing a version, which may drop the last open item.

When the level is finished, it:
- records the next level in `readiness_level_unlocks` (unique per factory and level, append-only);
- audits `factory.readiness_level_unlocked` with `from`, `to`, the plan id and the number of completed items;
- notifies the factory's members;
- repeats from the new level.

Smart opens nothing further.

**Permanence (interim, OQ-56).** An opened level is never closed again:
- adding a service to the old level, a new plan version, closing the plan, or a lower self-assessment leaves it open;
- a higher assessment raises the level further.

**Lock order.** IMC actions lock the plan, then the item. Progression then reads `readiness_level_services` and the published version without locks, and inserts the unlock row. Request creation reads unlock rows without a lock (an unlock only widens access) and keeps its order (factory, plan, item, level row, providers).

**The plan review is unchanged.** It still blocks a service above the factory's level. IMC adds the next level's services in a new version after the level opens.

### 4. Scores go to IMC only

**Rule.** Readiness scores are returned only to holders of `assessments.view_any` (`ReadinessAssessmentResource::showsScores()`). IMC administrators keep every score (owner decision).

**What a factory member, or anyone else, does not receive:**
- **assessments:** `total_score`, `min_score`, `max_score`, `pillars`, and the `points` of each answer;
- **categories:** `min_score` and `max_score`;
- **`GET /readiness-questionnaire`:** `min_score`, `max_score`, each choice's `points` and the category ranges, so the total cannot be worked out;
- **`current_readiness`:** `total_score`;
- **eligibility `readiness` and listings `meta.readiness`:** `total_score`.

**What the factory still receives:** the category, the level and its answers' texts.

**Unchanged:** scoring itself (ADR-018 §2) and the stored totals.

## Consequences

- **Contract changes:**
  - factory-side clients read the level from `readiness_level` or `readiness.level`;
  - `meta.readiness` of `GET /service-listings` now has the eligibility shape (`level`, `name_ar`, …) instead of `category` and `total_score`;
  - the factory screens show the level name and badge only.
- **Tests:**
  - tests that read scores as a factory member now read them as IMC or from the database;
  - `ServiceEligibilityTest` expects the Basic service to remain after a move to Advanced;
  - `ReadinessLevelProgressionTest` covers the rules above.
- **Open:**
  - whether an opened level should survive a lower reassessment, and whether IMC may close a level again (OQ-56);
  - who reports completion and on what evidence (OQ-52).
