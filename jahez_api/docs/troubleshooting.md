# Troubleshooting

Only issues **actually reproduced** in this project are listed. Add an entry when you hit and solve a new one.

## Tests: `PDOException … Unknown database` thrown after a test that changes the DB connection

- **Seen:** Phase 1, while writing `HealthTest`.
- **Cause:** `LazilyRefreshDatabase` rolls back its transaction after each test by calling `getPdo()` on the **default** connection. If a test changes that connection's config and purges it (`DB::purge()`), the teardown reconnects with the broken config and throws.
- **Fix:** leave the real connection alone. Register a separate connection (for example `database.connections.unreachable`), make it the default only for the request under test, and restore `database.default` straight afterwards. See `tests/Feature/Api/V1/HealthTest.php`.

## `AGENTS.md` lost its "Skills Activation" section after `boost:update`

- **Seen:** Phase 1.
- **Cause:** `php artisan boost:update --ignore-skills` regenerates `AGENTS.md` without the skills sections.
- **Fix:** run `php artisan boost:update --no-discover --no-interaction` (without `--ignore-skills`), then review `git diff AGENTS.md`. See [ADR-007](decisions/ADR-007-agent-guidelines.md).

## Larastan: `explode`/`rtrim` expects string, `bool|string` given in `config/*.php`

- **Seen:** Phase 1, in the upstream `config/filesystems.php` and `config/sanctum.php`.
- **Cause:** `env()` turns the string `"true"`/`"false"` into a boolean, so PHPStan types the result as `bool|string`.
- **Fix:** both files have since been edited for project reasons (Phase 3: `sanctum.php`; Phase 9: `filesystems.php`) and cast their input with `(string) env(...)`. `phpstan.neon` excludes nothing (see [ADR-005](decisions/ADR-005-static-analysis.md)). In project-written config, make sure the value is a string before string functions use it, as `config/cors.php` does with `(string) env('CORS_ALLOWED_ORIGINS', '')`.

## `MissingAttributeException: The attribute [deactivated_at] either does not exist or was not retrieved`

- **Seen:** Phase 3, on a model created in the same request (for example right after `User::create()` or a factory `create()`).
- **Cause:** `Model::shouldBeStrict()` (active outside production) throws when code reads an attribute a model never received. A freshly inserted row does not contain database defaults in memory.
- **Fix:** if application code reads the column before the model is reloaded, mirror the default in the model's `$attributes` (as `User` does for `deactivated_at`). Do not turn strict mode off.

## `Cannot redeclare … factory()` or a relation named `factory`

- **Seen:** Phase 3, while designing the `User` → `Factory` relation.
- **Cause:** every model using `HasFactory` already has a static `factory()` method.
- **Fix:** the relation is `User::industrialFactory()` with the explicit key `factory_id`.

## `make:model Factory -f` generates a broken factory class

- **Seen:** Phase 3.
- **Cause:** the generator does not append `Factory` to a name that already ends with it. It creates `database/factories/Factory.php`, which imports two classes both named `Factory`.
- **Fix:** the model factory is `database/factories/FactoryFactory.php`, which aliases the base class as `ModelFactory`. The same quirk affects `make:enum` with a sub-path once `app/Enums` exists: `make:enum Enums/X` creates `app/Enums/Enums/X.php`. Use `make:enum X`.

## A revoked or expired token still authenticates inside one test

- **Seen:** Phase 3 (anticipated and handled in `LogoutTest`).
- **Cause:** Laravel keeps the user a guard resolved for the rest of the test, so a second request in the same test does not re-check the token.
- **Fix:** call `forgetResolvedUsers()` (in `tests/Pest.php`) before the request that must re-authenticate.

## `Hash::partialMock()` breaks hashing (`Call to a member function get() on null`)

- **Seen:** Phase 3.
- **Cause:** the facade partial mock builds a fresh `HashManager` without configuration instead of wrapping the real one.
- **Fix:** use a full expectation, `Hash::shouldReceive('check')->once()->with(...)->andReturnFalse()`, and stub every Hash method the code path calls.

## The `security-review` skill fails with `ambiguous argument 'origin/HEAD...'`

- **Seen:** Phase 3.
- **Cause:** the skill diffs against `origin/HEAD`, and the repository has no remote yet ([OQ-28](open-questions.md#oq-28)).
- **Fix:** add the real remote when it exists. Until then, use the `code-review` skill, which works on the local diff.

## A test asserting `Artisan::output()` sees an empty string

- **Seen:** Phase 9, in `CheckProductionConfigurationTest`.
- **Cause:** `Artisan::output()` returns the buffered output **and clears it**, so a second call in the same test returns `''`.
- **Fix:** read it once into a variable (`$output = Artisan::output();`) and assert on the variable.

## A Pest dataset value typed `Closure` arrives uncalled

- **Seen:** Phase 9.
- **Cause:** Pest calls closures in a dataset to produce the value, **unless** the test parameter is typed `Closure`; then the closure itself is passed in.
- **Fix:** type the parameter as the produced value (for example `array`) and let Pest call the closure, or type it `Closure` deliberately and call it in the test.

## A `required_with` rule never fires

- **Seen:** Phase 9, on the audit filters `subject_id` / `subject_type`.
- **Cause:** `sometimes` skips every rule of an absent field, `required_with` included.
- **Fix:** do not combine `sometimes` with `required_with`/`required_if`. The other rules are skipped for absent fields anyway. Likewise, apply `after_or_equal:from` only when `from` is present.

## `node postman/run-collection.js` fails with `require is not defined in ES module scope`

- **Seen:** Phase 9.
- **Cause:** `package.json` declares `"type": "module"`, so Node treats every `.js` file in the project as an ES module.
- **Fix:** the runner now uses `import fs from 'node:fs'`. Since `package.json` was removed (ADR-013), it is named `postman/run-collection.mjs`, so it is an ES module with or without a `package.json`.

## The k6 smoke test fails in `setup()` although login "returns 200"

- **Seen:** Phase 10 preparation.
- **Cause:** PHP's built-in server was started from the project root with Laravel's `server.php`, which expects the **`public/`** directory as its working directory. Every request ended in a PHP fatal error, which the built-in server returns with **status 200**.
- **Fix:** start it from `public/`: `cd public && php -S 127.0.0.1:8765 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`. The k6 login check now requires a token in the JSON body, not just a 200.

## An environment variable set before `php artisan serve` has no effect

- **Seen:** Phase 10 preparation (raising `API_RATE_LIMIT_PER_MINUTE` for a local k6 run).
- **Cause:** `php artisan serve` passes only a short list of variables to its server process, so that `.env` is reloaded for every request.
- **Fix:** use PHP's built-in server directly (previous entry) with the variable set in front of it, or change `.env`.

## Starting the local MySQL 8.4 server

- **Where:** `%LOCALAPPDATA%\Programs\mysql-8.4.9-winx64`, data directory `%LOCALAPPDATA%\Programs\mysql-8.4-data`, configuration `my.ini` in the program folder (port 3307, `127.0.0.1` only). It is not a Windows service.
- **Start:** `"%LOCALAPPDATA%\Programs\mysql-8.4.9-winx64\bin\mysqld.exe" --defaults-file="%LOCALAPPDATA%\Programs\mysql-8.4.9-winx64\my.ini" --console` (leave it running). Stop it with `mysqladmin -h 127.0.0.1 -P 3307 -u root shutdown`.
- **Use:** `DB_PORT=3307 php artisan test --compact`. The first run creates `jahez_testing` objects; parallel runs create `jahez_testing_test_N`.

## The database cache store never counts some rate-limiter hits

- **Seen:** Phase 9 (finding FC-02), with `CACHE_STORE=database`.
- **Cause:** the `cache.key` column is 255 characters. `insertOrIgnore` silently truncates a longer key, and later lookups use the full key, so they never find the row.
- **Fix:** keep cache and rate-limiter keys short. Hash any key built from user input (as `LoginRequest` does).

## Reset or invitation emails never arrive

- **Cause:** they are sent by queued jobs (`SendPasswordResetLink`, `SendAccountInvitation`), and no worker is running. Locally, `MAIL_MAILER=log` writes them to `storage/logs/laravel.log` rather than sending them.
- **Fix:** run `php artisan queue:work`. Check `failed_jobs` with `php artisan queue:failed`.

## `php artisan serve` ignores `DB_*` overrides from the shell

- **Seen:** 2026-10-04, while running the Postman collection against an isolated database.
- **Cause:** `artisan serve` starts a child PHP server and passes it only a short list of environment variables (`APP_ENV`, `PATH`, ...; `ServeCommand::$passthroughVariables`). `DB_PORT=3307 DB_DATABASE=x php artisan serve` therefore serves the database in `.env`, not the one on the command line.
- **Fix:** to serve another database, run PHP's server directly so the variables reach the application: `cd public && DB_PORT=3307 DB_DATABASE=jahez_postman php -S 127.0.0.1:8010 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`. Check which database answers (for example, log in with an account only that database has) before sending requests that write.
