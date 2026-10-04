# Phase 5: Factory Self-Edit and Manual Classification

**Date:** 2026-10-03 · **Branch:** `phase/04-07-marketplace` · **Result:** complete for the owner-approved scope; gate **PASS for the approved scope** (§7)

Built together with Phases 4, 6 and 7 from one combined brief. The combined suite, analysis, mutation and Postman results are in the [Phase 6 log](phase-06-requests-offers-negotiation.md#4-tests-and-checks).

## 1. Scope

**Delivered:**
- **Factory self-edit:** a factory's own members may change its `name` and `sectors`. Nothing else is accepted from them.
- **Manual IMC classification** ([ADR-016](../decisions/ADR-016-manual-factory-classification.md)):
  - An IMC administrator records a maturity tier (the four DOC tiers), a mandatory justification and the assessment date.
  - The response shows the tier's source pathway and level for reference, `classification_method: "manual"` and `score: null`.
  - Records are append-only: a reclassification is a new record, and history is never overwritten.
  - `GET /factories/{id}/assessments` (administrators; the factory's own members, read-only) and `POST /factories/{id}/assessments` (permission `assessments.create`). The latest assessment date comes first; that entry is the current classification.

**Not delivered (blocked, not guessed):**
- Any score, scoring formula, questionnaire or tier thresholds ([OQ-06](../open-questions.md#oq-06), [OQ-07](../open-questions.md#oq-07)).
- The infrastructure and cyber audit, assessor roles ([OQ-08](../open-questions.md#oq-08)), evidence documents ([OQ-10](../open-questions.md#oq-10)), baseline indicators ([OQ-11](../open-questions.md#oq-11)) and roadmaps ([OQ-12](../open-questions.md#oq-12)).
- More factory profile fields: the workbook defines none ([OQ-19](../open-questions.md#oq-19)).

## 2. Decisions

**Owner (2026-10-03, OWNER-APPROVED):**
1. Phase 5 is **manual IMC classification only**: a tier chosen manually, a justification, the source pathway shown, no score and no questionnaire. This makes the OQ-07 interim owner-approved.
2. **Factory members edit their own factory's name and sectors.** This changed approved Phase 3 behaviour: the test "returns 403 to a member updating their own factory" became "lets a member update their own factory name and sectors". The test was rewritten for the new rule, not deleted, and a new test checks that other fields are ignored.

**Technical:**
- **No `score` column**, so a score cannot be stored by accident. A test checks the column does not exist and that a score sent by the client is ignored.
- **The model refuses updates and deletes**, the same pattern as the audit log.
- **`assessed_on`** must be a date no later than today.
- **Ordering:** the current classification is the one with the latest `assessed_on`, and the latest recorded on the same date. The first version ordered by id, so a backfilled older classification would have been listed as current. The code review caught this (CR-32), and it was fixed test-first.
- **Audit:** `factory.assessment_recorded` records the assessment ID, the tier code and the date.

## 3. Data model and permissions

- **Migration** `create_factory_assessments_table`: `factory_id`, `maturity_tier_id`, `justification`, `assessed_on`, `recorded_by_user_id` and `created_at` only. There is no `updated_at` and no score. Index `(factory_id, assessed_on, id)` serves the history query.
- **Permissions:** `assessments.view_any` and `assessments.create`.
- **Policy:** `FactoryAssessmentPolicy`. The factory's own member gets 403 when recording; anyone outside the factory and IMC gets 404, before the payload is validated.

## 4. Tests

| File | Tests | What it proves |
| --- | --- | --- |
| `Api/V1/FactoryAssessmentTest` | 18 | Manual classification with the source tier and pathway and no score; a client-sent score is ignored, and no score column exists; history kept; current = latest assessment date (backfill case); per-factory listing; validation (unknown tier, missing or long justification, future date); boundaries accepted (today, 5000 characters); member reads but cannot record; outsiders 404 before validation; 401; update and delete refused |
| `Policies/FactoryAssessmentPolicyTest` | 8 | `viewAny` and `create` for every actor type |
| `Api/V1/FactoryTest` | 19 (extended) | A member updates their own factory's name and sectors; other fields are ignored |
| `Policies/FactoryPolicyTest` | 11 (extended) | `update` allows own members |
| `Audit/AuditTrailTest` | shared | Classification recorded with ID, tier and date |

Mutation checks on the Phase 5 controls: 6, all caught. The list is in the [Phase 6 log](phase-06-requests-offers-negotiation.md#5-verification).

## 5. Docs updated

- [ADR-016](../decisions/ADR-016-manual-factory-classification.md) (new).
- [open-questions.md](../open-questions.md): OQ-07 note.
- [data-model.md](../data-model.md), [roles-permissions.md](../roles-permissions.md), [api-endpoints.md](../api-endpoints.md), [workflows.md](../workflows.md) §3.
- The Postman folders 03 (member renames their own factory) and 07.

## 6. Open questions affected

- **[OQ-06](../open-questions.md#oq-06), [OQ-07](../open-questions.md#oq-07):** still open for any scoring. The manual interim is owner-approved.
- **[OQ-19](../open-questions.md#oq-19):** still open; the workbook has no factory fields.

## 7. Exit gate

| Criterion | Evidence | Result |
| --- | --- | --- |
| No score, formula or threshold invented | No score column; `score: null` always; ADR-016 | PASS |
| Manual tier with mandatory justification; history kept; append-only | `FactoryAssessmentTest`; model guards; mutation-verified | PASS |
| Current classification well defined (latest assessment date) | Backfill test; mutation-verified (CR-32) | PASS |
| Cross-factory isolation (404 before validation); members read-only | HTTP and policy tests; mutation-verified | PASS |
| Factory self-edit limited to name and sectors | `FactoryTest` | PASS |
| Suite, Larastan, Pint, Postman on the combined branch | [Phase 6 log §4](phase-06-requests-offers-negotiation.md#4-tests-and-checks) | PASS |

**Gate: PASS for the approved scope** (manual classification). Scoring, questionnaires, audits and roadmaps remain blocked on OQ-06 to OQ-12.
