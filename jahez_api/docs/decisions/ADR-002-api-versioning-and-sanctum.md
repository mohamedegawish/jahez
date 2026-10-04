# ADR-002: URL-versioned JSON API with Sanctum installed

- **Status:** Accepted (2026-10-02)
- **Decided by:** Project owner (approval A2)

## Context
The skeleton had no API routing. The master brief asks for `/api/v1` or another documented versioning strategy.

## Decision
- `php artisan install:api` enabled `routes/api.php` (the `api` middleware group, `/api` prefix) and installed **laravel/sanctum ^4.0**, including its `personal_access_tokens` migration.
- Every API route lives inside `Route::prefix('v1')->name('api.v1.')` in `routes/api.php`. `tests/Feature/Api/ApiVersioningTest.php` fails if any `api/*` route sits outside `api/v1/`.
- The scaffolded `GET /api/user` route was **removed**. It was unversioned and returned the raw `User` model rather than an API Resource. A replacement "current user" endpoint belongs to Phase 3.
- Breaking changes go into a new version group (`v2`). Additive changes are allowed within `v1`.

## Not decided here
- The authentication mode: Sanctum SPA cookies vs bearer tokens (ADR-003, Phase 3, [OQ-22](../open-questions.md#oq-22)). `HasApiTokens` is **not** yet on `User`.
- Token expiry. Sanctum's `expiration` is still `null` (tokens never expire). It **must** be set in Phase 3 (risk RK-13).
