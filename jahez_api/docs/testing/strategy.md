# Testing Strategy

## 1. Tools

| Tool | Version | Use |
| --- | --- | --- |
| Pest | 3.8 (PHPUnit 11.5) | Unit, feature and architecture tests |
| Larastan / PHPStan | 3.12 / 2.2, level 8 | Static analysis (`composer analyse`) |
| Laravel Pint | 1.30 | Formatting |
| MySQL-compatible server | Local: MariaDB 10.4.32 (default) and MySQL 8.4.9 on port 3307 (see [ADR-004](../decisions/ADR-004-database-mysql.md)) | Test database `jahez_testing` on each |
| gitleaks | 8.30.1 | Secret scan of the full git history |
| k6 | 2.2.0 | Performance scripts in `tests/performance/` ([plan](../performance/plan.md)) |

**Not available yet:** Newman (Postman CLI), Locust, CI (no remote), a staging environment.

## 2. Commands

```bash
php artisan test --compact                                  # full suite
php artisan test --compact tests/Feature/Api/ErrorResponseTest.php
php artisan test --compact --filter="request ID"
php artisan test --parallel --processes=4                   # uses jahez_testing_test_1..4
DB_PORT=3307 php artisan test --compact                     # the same suite on MySQL 8.4 (start it first, see troubleshooting)
gitleaks git --log-opts="--all" --redact .                  # secret scan of every commit on every branch
k6 run tests/performance/smoke.js -e BASE_URL=http://127.0.0.1:8765   # see docs/performance/plan.md
vendor/bin/pest --profile                                   # find slow tests
composer analyse                                            # Larastan level 8
vendor/bin/pint --dirty --format agent                      # format changed files
```

Before handing work over, every change must pass `php artisan test --compact`, `composer analyse` and Pint.

## 3. Test environment

- **Database:** MySQL `jahez_testing`, configured in `phpunit.xml`. The host, user and password come from `.env`. Feature tests use `LazilyRefreshDatabase`: migrations run once per run, the first time a test touches the database, and each test runs in a transaction that is rolled back.
- **Global hooks** (`tests/Pest.php`, Feature suite): `Http::preventStrayRequests()` makes any unfaked outbound HTTP call fail; `Sleep::fake(syncWithCarbon: true)` means retries never really sleep.
- `phpunit.xml` sets `BCRYPT_ROUNDS=4`, array cache/session/mail and the `sync` queue.
- `Model::shouldBeStrict()` is active outside production. Lazy loading, silently discarded attributes and missing attributes all throw in tests.

## 4. Layers and locations

| Layer | Location | What belongs there |
| --- | --- | --- |
| Architecture | `tests/Unit/ArchitectureTest.php` | Pest `php` and `security` presets; `env()` is never used in `App` |
| Unit | `tests/Unit/` | Pure logic without the framework: state-transition tables (`ServiceRequestStatusTest`, Phase 6); calculations once rules are approved |
| Feature / API | `tests/Feature/Api/…` | HTTP behaviour: status, envelope, headers, persisted state |
| Policy matrices | `tests/Feature/Policies/` (from Phase 3) | Full actor × ability matrix at the policy level, including 403 vs 404 |
| Jobs / console / config | `tests/Feature/Jobs/`, `tests/Feature/Console/`, `tests/Feature/Config/` (from Phase 3) | Job behaviour run directly; artisan commands with prompted input; config fallbacks; the `app:check-production` gate |
| Audit trail | `tests/Feature/Audit/`, `tests/Feature/Models/` (from Phase 8) | Each security event and its metadata, through the real endpoints; atomicity (a failing audit write rolls the change back); model-level immutability and secret filtering |
| Database | `tests/Feature/Database/` (from Phase 2) | Seeder completeness, source fidelity and idempotency (since Phase 4 `ServiceCatalogSourceTest` reads the workbook `.xlsx` itself); unique, FK and mass-assignment constraints |
| Concurrency | Feature tests, run on both engines (from Phase 6) | Lock sequences asserted from the SQL log (`lockingReads()`); state guards by sequential repeats (second acceptance, stale offer → 409); rollback through a failing audit write; races between validation and saving reproduced with an `afterResolving` hook. Truly parallel requests are not possible inside the test transaction ([RK-23](../implementation-plan.md#5-risk-register)). |
| Contract / smoke | `postman/` | Run by Newman once it is available |
| Performance | `tests/performance/` (since Phase 10 preparation) | k6 scripts (`smoke.js`, `read-load.js`); results in `docs/performance/results/`. Not run by Pest. |

## 5. Rules for new tests

Adapted from the `testing-best-practices` skill, which you should read before writing tests:

- For each endpoint, cover: no or invalid authentication, a **different tenant (expect 404)**, an insufficient role, invalid input (assert the exact message text) and the valid case. For the valid case, assert the response **and** the database state.
- Assert meaningful fields, not just the status code.
- Use datasets for input variants that share setup and assertions.
- Use framework fakes created inside the test (`Exceptions::fake()`, `Queue::fake([...])`). Never mock the database or the query builder.
- Freeze or travel time for anything time-dependent.
- Each test creates its own data. No test depends on another test's records or on execution order. The suite must pass with `--parallel`.
- Tests may register throwaway routes under `api/v1/__test/…` with the `api` middleware group to exercise cross-cutting behaviour.
- Never delete or weaken a failing test to make the suite green.
- Compare JSON columns read back from the database with `toEqual()`, not `toBe()`. MySQL 8 stores JSON object keys sorted, so key order is not preserved (CR-14).
- To prove that a transaction rolls back when its audit entry fails, register `AuditLog::creating(fn () => throw new RuntimeException(...))` inside the test (see `AuditTrailTest › atomicity`).
- To test locking, open a second connection with the same config (`config(['database.connections.second' => ...])`) and `SET SESSION innodb_lock_wait_timeout = 1`. The test's own transaction holds the locks, so a wait shows up as an error within a second (see `Models/AuditLogTest`). Roll the second connection back and purge it in `finally`. This works only for locks on rows the test itself wrote: the second connection cannot see the test's uncommitted rows, so it cannot hold a lock that the code under test then waits for.
- To check which rows an action locks, and in what order, wrap the request in `lockingReads(fn () => …)` (`tests/Pest.php`). It returns the locking reads as `table:update` or `table:share` (see `NegotiationTest` › locks the request row before the thread row…).
- To reproduce a change that lands between validation and the controller, register `app()->afterResolving(SomeFormRequest::class, fn () => …)` in the test. It runs after the form request has validated (see `ServiceRequestTest` › checks eligibility again when saving…).
- To test behaviour that depends on the database cache store (the production default), swap the rate limiter for one on `Cache::store('database')`, re-registering the named limiters (see `LoginTest › counts failed attempts for a long email address…`). The suite itself uses the array store.

## 6. Checking that tests can fail

A test that cannot fail is worthless. For cross-cutting behaviour, temporarily break the implementation and confirm the tests go red. In Phase 1, unregistering `ApiExceptionRenderer` made 9 of the 10 `ErrorResponseTest` cases fail. The tenth checks that web routes are untouched and correctly kept passing.

From Phase 9, every security fix is written **test first**: the regression test is run against the unfixed code and must fail for the expected reason. The control is then checked with a scripted mutation: an exact text replacement, one test-file run, then the original restored. Phase 9 ran 41 mutations, all caught; the 2 survivors of the first round each got a new test ([log](../phases/phase-09-hardening.md#43-mutation-checks)). Phases 4–6 ran 46, all caught, with the sources compared to a backup afterwards ([log](../phases/phase-06-requests-offers-negotiation.md#51-mutation-checks)).

## 7. Current inventory

**823 tests, 2572 assertions**, all passing on MariaDB 10.4 and MySQL 8.4 after the business-logic completion (2026-10-03; [Phase 6 log §9](../phases/phase-06-requests-offers-negotiation.md#9-business-logic-completion-2026-10-03)). Before it there were **555 tests (1593 assertions)**, all passing on both engines, serial and with `--parallel --processes=4`, after Phases 4–7. Phases 4–6 added 271 tests ([Phase 4](../phases/phase-04-catalog-providers.md#5-tests), [Phase 5](../phases/phase-05-factory-assessments.md#4-tests), [Phase 6](../phases/phase-06-requests-offers-negotiation.md#41-test-suite-phases-47-combined-final)). After the Phase 10 preparation there were 284 tests (825 assertions); the one test added since Phase 9 is the Sanctum CSRF-route test. Phases 8–9 added 90 tests ([breakdown](../phases/phase-09-hardening.md#41-test-suite-final)). At the end of Phase 3 there were 193 tests (511 assertions):
- Phase 1 tests: 35 ([breakdown](../phases/phase-01-foundation.md#4-test-and-check-results)).
- Phase 2 database tests in `tests/Feature/Database/`: 15 ([breakdown](../phases/phase-02-data-model.md#4-test-and-check-results)).
- Phase 3 tests: auth, current user, factories, providers, users, policies, jobs, console, config and DB constraints ([breakdown](../phases/phase-03-auth.md#4-test-and-check-results)).

### Authentication helpers (`tests/Pest.php`)
- Use `Sanctum::actingAs($user)` for **authorization** tests.
- Use `bearerTokenFor($user)` plus `withToken()` when the test is about the **token itself**: expiry, revocation, deactivation. These go through Sanctum's real guard.
- Call `forgetResolvedUsers()` between two requests in one test when the second must re-authenticate. Laravel otherwise keeps the user the guard already resolved, which would hide a revoked token.
- Factory states: `User::factory()->imcAdmin()`, `->factoryMember($factory)`, `->providerMember($provider)`, `->deactivated()`. The default user is a factory member, the least-privileged role.
- Organization states (Phase 4): `Factory::factory()->inSectors('food')`, `ServiceProvider::factory()->approved()`, `->withApprovalStatus(...)`, `->inSectors(...)`, `->offering('erp_business_applications.01', ...)`. They take real codes, so seed the reference data first.

### Marketplace helpers (`tests/Pest.php`, Phase 6)
- `marketplaceRequest($providerCount, $status)` seeds the reference data and builds a food-sector factory with a member, one service request, and one thread per approved provider, each with a member. It returns `factoryMember`, `serviceRequest`, `threads` and `providerMembers`.
- `offerVersion($thread, $version, $author)` stores an offer version directly, as if the provider had submitted it.
- `lockingReads(fn () => …)` returns the locking reads an action runs (see §5).
- `agreedMarketplace()` builds a marketplace whose first thread the factory has agreed through the API, and returns its `agreement` too.
- `configureBilling($overrides)` sets the billing rules a test needs (issuer, numbering, tax, revenue share, gateway); without it every money operation is refused, as in production today.
- `draftInvoice()` builds an agreement and the provider's draft invoice.
- `Tests\Fakes\FakePaymentGateway` is a **test-only** gateway adapter: it signs and verifies callbacks with HMAC, never reports success when starting a payment, and can fail on demand. Register it with `configureBilling(['jahez.billing.payment_gateway' => 'fake', 'jahez.billing.gateways' => ['fake' => FakePaymentGateway::class]])` and call `FakePaymentGateway::reset()` in `beforeEach`.

Reference data in tests: seed it with `$this->seed(ReferenceDataSeeder::class)` inside the test that needs it. There are deliberately no factories for reference models, because tests must use the real source rows, not invented sectors or tiers.
