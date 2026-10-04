# ADR-013: API-only application

- **Status:** Accepted (2026-10-03). Answers [OQ-34](../open-questions.md#oq-34).
- **Decided by:** Project owner ("Yes, remove the welcome page", 2026-10-03).

## Decision
This repository is the JSON API only. Client applications live elsewhere and call `/api/v1` with bearer tokens ([ADR-003](ADR-003-authentication-bearer-tokens.md)).

- **No web routes.** `routes/web.php` and the welcome page are removed, and `bootstrap/app.php` registers no web routes. Outside `/api`, the only route is the framework's liveness probe `GET /up`, which runs without the `web` middleware.
- **No Sanctum routes.** `config/sanctum.php` sets `routes => false`, which removes `GET /sanctum/csrf-cookie` (cookie authentication is disabled). CORS covers `api/*` only.
- **No front-end tooling.** Vite, Tailwind, `package.json`, `package-lock.json` and `resources/{css,js}` are removed. Node.js is only needed for tools: the Postman runner (`postman/run-collection.mjs`, an ES module that needs no `package.json`), and optionally Newman.
- `resources/views/` stays (empty, with `.gitkeep`) so that `php artisan optimize`/`view:cache` find their default path. Emails use the framework's built-in notification views.
- **Sessions are never started**, so `.env.example` uses `SESSION_DRIVER=array`. The `sessions` table from the skeleton migration stays and is unused.

## Consequences
- Closes finding FC-09 at its source (`GET /` wrote a session row per visitor) and FC-17 (the same through `/sanctum/csrf-cookie`).
- `composer run dev` and the `npm` steps of `composer run setup` are gone. Run `php artisan serve` and `php artisan queue:work` separately (README).
- `npm audit` no longer applies; `composer audit` remains.
- Adding web pages or cookie authentication later needs a new ADR, because both reintroduce sessions, CSRF and front-end dependencies.
- With Tailwind gone, `php artisan boost:update` removed the `tailwindcss-development` skill (from `boost.json` and `.claude/skills/`). The `AGENTS.md` that Boost generates still contains its generic "Frontend Bundling" section, which cannot be edited by hand. The project rule in `.ai/guidelines/jahez.md` (API-only) takes precedence.
