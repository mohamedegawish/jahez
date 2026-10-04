# ADR-016: Manual IMC classification of factories

- **Status:** **Superseded** by [ADR-018](ADR-018-digital-readiness-assessment.md) (2026-10-03). Accepted 2026-10-03, Phase 5. Recording a manual classification is no longer possible; the records made under this ADR stay readable as history at `GET /factories/{id}/assessments`.
- **Decided by:** Project owner ("Manual IMC classification", 2026-10-03). This makes the documented [OQ-07](../open-questions.md#oq-07) interim behaviour the approved Phase 5 scope.

## Context

The source document defines four maturity tiers with qualitative readiness bands, each mapped to an approved pathway (seeded in Phase 2). It does **not** define:
- the assessment questionnaire, the scales, the weights or the formula ([OQ-06](../open-questions.md#oq-06));
- the numeric thresholds between tiers ([OQ-07](../open-questions.md#oq-07)).

Inventing any of these is not allowed.

## Decision

- **Record:** an IMC administrator (permission `assessments.create`) records a classification for a factory:
  - a **tier chosen manually** from the four source tiers;
  - a **mandatory justification**;
  - the assessment date, today or earlier.
- **Response:** the tier, its source readiness band and its **source pathway** for reference, `classification_method: "manual"` and `score: null`. There is **no score column**.
- **History:** append-only `factory_assessments`. A reclassification is a new row, so history is never overwritten. The row with the latest assessment date (then the latest recorded) is the current classification, so a backfilled older classification never replaces it.
- **Reading:** IMC administrators (`assessments.view_any`) and the factory's own members (read-only).
- **Audit:** `factory.assessment_recorded` (tier and date).

## Not built

- The questionnaire, answers and a computed score: blocked on [OQ-06](../open-questions.md#oq-06) and [OQ-07](../open-questions.md#oq-07).
- Self-assessment: [OQ-08](../open-questions.md#oq-08).
- Path gating: [OQ-09](../open-questions.md#oq-09).
- Roadmaps and baseline indicators: [OQ-11](../open-questions.md#oq-11), [OQ-12](../open-questions.md#oq-12).

When a scoring method is approved, it gets its own versioned table. The manual classifications stay as they are, marked `manual`.
