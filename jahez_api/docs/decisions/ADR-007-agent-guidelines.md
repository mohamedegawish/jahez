# ADR-007: Project rules for AI agents live in `.ai/guidelines/`

- **Status:** Accepted (2026-10-02)
- **Decided by:** Project owner (approval A4)

## Context
Laravel Boost generates `AGENTS.md`, and `composer update` runs `php artisan boost:update` automatically (a `post-update-cmd` script). Direct edits to `AGENTS.md` would be overwritten.

## Decision
- Jahez-specific rules live in `.ai/guidelines/jahez.md`. Boost merges this file at the top of `AGENTS.md` as `=== .ai/jahez rules ===`.
- After editing the guideline, regenerate with `php artisan boost:update --no-discover --no-interaction`.
- **Do not pass `--ignore-skills`.** Verified in Phase 1: with that flag, Boost drops the "Skills Activation" section and the `deploying-to-cloud` hint from `AGENTS.md`.

## Consequences
`AGENTS.md` is a generated file and should never be edited by hand. Review its diff after each regeneration.
