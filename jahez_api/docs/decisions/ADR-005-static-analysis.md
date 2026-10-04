# ADR-005: Larastan at level 8

- **Status:** Accepted (2026-10-02)
- **Decided by:** Project owner (approval A3)

## Decision
- `larastan/larastan ^3.12` (PHPStan 2.x) as a dev dependency. Configuration in `phpstan.neon`, run with `composer analyse`.
- **Level 8**, covering `app/`, `bootstrap/app.php`, `config/`, `database/` and `routes/`. Tests are excluded because Pest closures produce noise without catching useful defects.
- **No baseline file and no `@phpstan-ignore` comments.** New errors are fixed at the cause.

## Exclusions
Update (Phase 9): `config/filesystems.php` was edited (`serve => false` for the local disk, and its `APP_URL` cast to string), so it was removed from the exclusions as well. **`phpstan.neon` now excludes nothing.**

Update (Phase 3): `config/sanctum.php` was edited for ADR-003, so following the rule below it was **removed from the exclusions** and fixed (`(string) env(...)`). Only `config/filesystems.php` is still excluded.

Original (Phase 1): `config/filesystems.php` (skeleton) and `config/sanctum.php` (published by Sanctum) are excluded. Both are unmodified upstream templates whose `env()` calls PHPStan types as `bool|string` passed to `rtrim()`/`explode()`. The only failure case is setting `APP_URL` or `SANCTUM_STATEFUL_DOMAINS` to the literal `true`/`false`, and in non-strict PHP that coerces to `"1"` rather than crashing. Leaving these files byte-identical to upstream keeps framework upgrades simple.

**If either file is edited for project reasons, remove it from `excludePaths` and fix its errors.** Project-written config (`config/api.php`, `config/cors.php`) is analysed.
