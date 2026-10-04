# ADR-001: Version control with git

- **Status:** Accepted (2026-10-02)
- **Decided by:** Project owner (approval A1, given as "start next phase" after the Phase 0 report)

## Context
In Phase 0 the project was not under version control ([OQ-28](../open-questions.md#oq-28)). Changes could not be reviewed as diffs, overwritten work could not be recovered, and the diff-based review skills could not run.

## Decision
- `git init` on branch `main`. Commit `020f811` records the untouched Laravel skeleton plus the Phase 0 documentation.
- Each phase is developed on its own branch named `phase/NN-short-name` (Phase 1: `phase/01-foundation`).
- `.env`, `vendor/`, `node_modules/` and SQLite files stay ignored (the skeleton's `.gitignore` rules). The baseline staging was checked for secrets before the commit.

## Consequences
- Phase work can be reviewed with `git diff main...phase/NN-…`.
- Changes are committed only when the owner asks.
- **Still open:** a remote (GitHub/GitLab), the merge policy and CI ([OQ-28](../open-questions.md#oq-28)).
