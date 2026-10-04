# ADR-010: Reference data from the source document

- **Status:** Accepted (2026-10-02, Phase 2)
- **Decided by:** Engineering, under owner approval A6. The language part is an **interim** until [OQ-23](../open-questions.md#oq-23) is answered.

## Context
DOC (التحول الصناعي الذكي) defines closed sets that later modules depend on: sectors, the two pathways and four levels with their scope items and per-level provider requirements, four maturity tiers, and five weighted provider-evaluation criteria. The PDF's text layer is garbled (right-to-left extraction), so the Arabic text had to be transcribed from the page images.

## Decision
1. **Relational tables** (`sectors`, `pathways`, `pathway_levels`, `pathway_scope_items`, `level_provider_requirements`, `maturity_tiers`, `evaluation_criteria`), not PHP enums. They hold display text that the owner may correct, and they have foreign-key relationships.
2. **A stable `code`** (snake_case English slug) on every top-level set is the seeder's upsert key and the identifier code depends on. Display text is never a key.
3. **Language interim:** `*_ar` columns hold the authoritative Arabic text, transcribed verbatim. Only whitespace around punctuation and the placement of parentheses inside bidirectional text were normalised. `*_en` columns are nullable and filled **only** with English the source itself prints (for example `Basic DX`, `Foundation Tier`). No translation was added.
4. **Only seeders write reference data** (`ReferenceDataSeeder` → `SectorSeeder`, `PathwaySeeder`, `MaturityTierSeeder`, `EvaluationCriterionSeeder`). They are idempotent: they match rows by `code`, `(version, code)` or `(level, position)`, remove surplus child items, and run in one transaction. They are safe to run in production.
5. **Things that are not decided stay unset:** `maturity_tiers.score_min/score_max` are NULL and excluded from `$fillable` ([OQ-07](../open-questions.md#oq-07)). Evaluation criteria store the source weights as **version 1**, but no scoring scale or pass mark ([OQ-13](../open-questions.md#oq-13)).
6. **`restrictOnDelete`** on every foreign key between reference tables. Reference rows that are in use cannot be deleted.

7. **Later sources follow the same rules:** the service catalog (ADR-014, `ServiceCatalogSeeder`) and the digital readiness questionnaire (ADR-018, `ReadinessAssessmentSeeder`) are seeded by `ReferenceDataSeeder` in the same transaction.

## Consequences
- Corrections from the owner's proofreading ([OQ-32](../open-questions.md#oq-32)) are made in the seeder arrays and applied by re-running `php artisan db:seed --class=ReferenceDataSeeder --force`.
- If OQ-23 decides on bilingual data, a later migration and seeder update fill `*_en`. No schema change is needed for the columns that already exist.
- A future change of evaluation weights adds version 2 rows. Version 1 rows are never edited in place once evaluations reference them.
