# ADR-018: Digital readiness assessment and score-based classification

- **Status:** Accepted (2026-10-03). **Supersedes [ADR-016](ADR-016-manual-factory-classification.md)** (manual IMC classification).
- **Decided by:** Project owner. The owner supplied the framework document and asked for factories to be classified through it ("Implement the documented assessment and automatic score-based classification", 2026-10-03). Four further choices were made in the same session (listed under Decision).
- **Source:** `docs/إطار تقييم مستوى الجاهزية الرقمية.docx` ("RDA" in the traceability matrix). Its sections:
  - §1: the five pillars;
  - §2: the scoring (أ 1, ب 2, ج 3, د 4; ten questions; totals 10–40);
  - §3: the four categories with their score ranges;
  - §4: the questionnaire;
  - §5: the roadmap of each category.

## Context

ADR-016 classified factories by hand because no questionnaire, scale or thresholds existed (OQ-06, OQ-07). The RDA document now defines all three, plus recommendations per category.

The RDA categories are not the concept paper's four maturity tiers:
- **Different source:** they come from another document.
- **Different names and descriptions:** B4 Automation, Basic, Advanced and Smart.
- **No stated link:** neither document says the two sets correspond.

## Decision

1. **Versioned questionnaire.** Seven reference tables hold the questionnaire, seeded by `ReadinessAssessmentSeeder` from the source text: `readiness_questionnaires`, `readiness_pillars`, `readiness_questions`, `readiness_choices`, `readiness_categories`, `readiness_recommendations`, and the pivot `catalog_service_readiness_recommendation`.
   - **Version 1** is the RDA document.
   - **Current version:** `is_current` (true or NULL, unique) marks the one version factories answer.
   - **Answered versions are frozen:** the seeder refuses to change the questions, choices, points, pillars or category ranges of a version that has assessments. A change needs a new version.
2. **Scoring on the server only.**
   - **Calculation:** the total is the sum of the points stored for the selected choices. The category is the one whose range contains the total, using the ranges of the version answered (`ReadinessQuestionnaire::categoryForScore()`).
   - **Client values ignored:** a score, category or points sent by the client are never read.
   - **No other formula:** there is no weighting, normalisation or extra category.
3. **One atomic submission.**
   - **Who submits:** a factory's own members (a self-assessment, owner decision).
   - **What they send:** one choice per question of the current version, as question and choice ids.
   - **How it is stored:** the assessment, its ten answers and the audit entry are written in one transaction. An incomplete assessment cannot be stored, and there are no drafts.
4. **Reproducible history.**
   - **What is kept:** each answer keeps the points it was worth, and each assessment keeps its total, category and version.
   - **Append-only:** assessments and answers are never updated or deleted.
   - **Database guarantees:**
     - a composite foreign key makes each answer's choice belong to its question;
     - another makes the category belong to the version answered;
     - a unique key allows one answer per question.
   - **Model guard:** the model refuses a total that no category covers, and a category that does not match the total.
5. **Current classification.** The factory's current classification is its latest assessment, by `completed_at` (set by the server) and then id. `FactoryResource` shows it as `current_readiness`.
6. **Manual classification retired.**
   - `POST /factories/{id}/assessments` is removed: it now answers 405, and the `assessments.create` permission is gone.
   - **Legacy history kept:** existing manual classifications stay readable at `GET /factories/{id}/assessments`.
   - **No conversion:** they are not converted into scores, because no answers exist for them, and they never set `current_readiness`.
7. **Categories separate from the DOC maturity tiers** (owner decision).
   - `maturity_tiers` is unchanged, and its `score_min` and `score_max` stay NULL.
   - Whether the two sets correspond is [OQ-41](../open-questions.md#oq-41).
8. **Recommendations are not eligibility.**
   - **Recommendation lines:** each category's §5 service lines are stored with the source text.
   - **Catalog mapping:** each line maps to the existing catalog services that carry the same text. «إعادة هندسة ورقمنة العمليات.» names two catalog services and maps to both (owner decision). «إدارة التدريب» maps to «إدارة التدريب والثقافة الرقمية.», which is workbook rows 59–60.
   - **Unmapped lines:** lines the catalog does not offer map to nothing ([OQ-42](../open-questions.md#oq-42)), and no catalog service is created for them.
   - **Filters:** `filter[recommended]` on the provider directory narrows the eligible providers to those offering a recommended service. Eligibility stays as it was (approved, and in one of the factory's sectors), so the filter never adds a provider. `filter[recommended]` on the catalog lists the recommended services.
9. **Audit.** `factory.readiness_assessment_completed` records the assessment id, version, total and category; answer text is never logged.
10. **Access.**
    - **Factory members:** read their own factory's assessments.
    - **IMC administrators** (`assessments.view_any`): read every factory's assessments but submit none.
    - **Anyone else:** gets 404.

## Consequences

- **Reference data:** the RDA questionnaire is reference data under [ADR-010](ADR-010-reference-data.md). It is safe to re-seed in production, and text corrections are applied in place.
- **Changing scores:** a later change to the scores needs a version 2. The tests show how one is introduced, and that version 1 results keep their totals and categories.
- **Open questions:** OQ-06 and OQ-07 are answered for the readiness categories. OQ-08 is partly answered: factories self-assess, and expert validation is still open.
- **Source anomalies kept verbatim:** the stray «)» after «(ERP).» and «(Workflow Management).»; and the Basic line printed twice, which is seeded once. Both are listed for proofreading in [OQ-32](../open-questions.md#oq-32).

## Addendum (2026-10-03): questionnaire administration and duplicate submissions

Owner brief "Phase 1 — Identity, Onboarding, Profiles & Assessment Foundation" asked for administrators to manage the questions, choices, ordering and classification configuration without silently rewriting historical scores. Applied as follows:

1. **Versions, not edits.** `readiness_questionnaires.published_at` marks a version that has been current. A published version is never changed or deleted. IMC administrators (`readiness_questionnaires.manage`) work on a **draft**:
   - `POST /readiness-questionnaires` copies the current version (pillars, questions, choices, points, categories, roadmap recommendations and their catalog mappings) into version N+1. One draft at a time (409).
   - `PUT /readiness-questionnaires/{id}` replaces the draft's structure: pillars, questions and choices in display order, and the texts and score ranges of the four categories. Rows are matched by code, so ids stay stable. The four category codes are fixed (no category can be added). The category ranges must cover every possible total exactly once, from the sum of the lowest to the sum of the highest choice points; every question needs 2–10 choices.
   - `POST /readiness-questionnaires/{id}/publish` checks the structure again, retires the current version (`is_current` NULL) and makes the draft current. `DELETE` removes a draft.
   - Roadmap recommendations are copied unchanged; editing them is not built.
2. **History preserved.** Assessments keep their version, answers (with points), total and category; publishing a new version never recalculates them. The seeder now makes version 1 current only when no version is current, so re-seeding never switches factories back from a published later version.
3. **Duplicate submissions.** `POST /factories/{id}/readiness-assessments` accepts an optional `Idempotency-Key` header (8–100 of `A-Za-z0-9_-`, as for payments). The same key for the same factory returns the stored assessment with 200 instead of creating a second one; the factory row is locked so two simultaneous copies are stored once. The web client sends one key per attempt.
4. **Audit:** `readiness_questionnaire.drafted`, `.updated` (version, question count, category ranges), `.published` (version, previous version), `.deleted`.

The guideline "reference data changes only through its seeders" now has this exception for the readiness questionnaire: version 1 remains the seeded source text; later versions are owner-managed data.

## Addendum 2 (2026-10-04): locked shape, editable roadmap and history

Owner brief "Dynamic Assessment Administration, Card-Based Listings & Realistic Demo Data" asked for a complete administration area (pillars, questions and choices, levels, roadmap, results, versions), validation before publishing, and historical results that never change. Two choices were made by the owner in the same session (questions asked during planning); the rest follows the brief.

1. **The source shape is locked** (owner decision). Every version keeps the document's five pillars, two questions per pillar and four choices per question worth 1, 2, 3 and 4 points, once each (`App\Readiness\QuestionnaireShape`), so every version scores 10–40.
   - `PUT /readiness-questionnaires/{id}` accepts the draft's own pillar, question and choice codes only: they may be reordered and reworded, never added, removed or moved to another pillar. Choice labels must be filled in and distinct within a question.
   - `definitionProblems()` checks the same rules, so `publish` refuses a draft whose stored rows break them (422 `definition`).
   - This replaces the addendum-1 limits (2–10 choices, points 0–100, any number of pillars and questions). The version tests were updated to the new contract.
2. **Editable roadmap.** A category in the draft definition may carry `recommendations[1–30]: {text_ar, services: [catalog codes]}`; the draft's lines are replaced and mapped to existing catalog services only (`exists`), never creating one. Without the key the lines are kept. A level without a recommendation cannot be published. Version 1 and its seeder are unchanged.
3. **Who and when.** `readiness_questionnaires.created_by_user_id`, `updated_by_user_id` and `published_by_user_id` record the drafting, the last change and the publication; the version resource returns them with `updated_at`. The audit log keeps the full history as before; the `updated` entry adds the number of roadmap lines per category when they were sent (never their text).
4. **History that cannot drift.** Each answer stores `question_text_ar`, `choice_label_ar` and `choice_text_ar` as given (migration `2026_10_06_100001`, which backfills earlier answers with the text their version holds at migration time). Version-1 text corrections applied in place by the seeder (OQ-32) no longer change what a stored assessment shows. Points, totals and categories were already stored.
5. **One scorer.** `App\Readiness\ReadinessAssessmentRecorder` is the only code that scores, classifies and stores an assessment: the API and the demo seeder (ADR-024) both use it. It refuses a set of choices that does not answer every question once and a version that is not current.
6. **No correction workflow** (owner decision). No endpoint changes a stored score, category or answer; a factory's classification changes only by a new submission. Who may correct a result, and on what grounds, is [OQ-51](../open-questions.md#oq-51). Factory approval stays separate from the readiness level (ADR-021).
7. **Result filters.** `GET /readiness-assessments` adds `filter[score_min]`, `filter[score_max]` (inclusive) and `filter[factory]`.

The administration UI shows seven tabs (overview, pillars, questions and choices with a factory-view preview, levels, roadmap, results, versions) and a review of every change against the current version before publishing; the API re-checks everything.
