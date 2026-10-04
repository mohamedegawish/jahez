# ADR-004: MySQL as the database engine

- **Status:** Accepted, with the production version still open (2026-10-02)
- **Decided by:** Project owner ("use mysql")

## Context
The skeleton used SQLite for development and SQLite `:memory:` for tests. SQLite cannot check row locking (`lockForUpdate()` does nothing on it) and differs from MySQL in constraint, collation and JSON behaviour (risk RK-07).

## Decision
- **Target engine: MySQL**, through Laravel's `mysql` connection, with `utf8mb4` / `utf8mb4_unicode_ci` pinned in `config/database.php`.
- **Development** database: `jahez`. **Test** database: `jahez_testing` (set in `phpunit.xml`). Both use `utf8mb4_unicode_ci`. Parallel test runs create `jahez_testing_test_N`.
- SQLite is no longer used by development or tests.

## Known deviation
The only MySQL-compatible server on the current development machine is **MariaDB 10.4.32** (bundled with XAMPP). That version is past its community end-of-life. MariaDB speaks the MySQL protocol, and Laravel's `mysql` driver works with it, but some behaviour differs from MySQL 8.x: JSON storage, default collations, some DDL, and optimizer and `EXPLAIN` output. Until a real MySQL server is used:
- Performance figures and `EXPLAIN` plans from this machine are **not** valid for MySQL production (Phase 10 must use the production engine).
- Concurrency tests (Phase 6/7) must be re-run on MySQL before release.

## Follow-ups
- Production MySQL version (recommendation: 8.4 LTS), hosting and data residency: [OQ-24](../open-questions.md#oq-24).
- ~~Install MySQL 8.4 locally or in CI to match production.~~ **Done locally (2026-10-03, approval A11):** MySQL 8.4.9 (official ZIP, signature verified) runs on `127.0.0.1:3307`, and the full suite passes on it (`DB_PORT=3307 php artisan test`). MariaDB 10.4 remains the default local server. CI waits for a remote ([OQ-28](../open-questions.md#oq-28)).
