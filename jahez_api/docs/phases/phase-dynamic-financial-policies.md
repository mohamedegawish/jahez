# Phase log: dynamic financial policies, billing and contract management (owner brief, 2026-10-04)

Decision: [ADR-023](../decisions/ADR-023-financial-and-contract-policies.md) (supersedes the billing environment settings of [ADR-017](../decisions/ADR-017-agreements-contracts-billing.md)). Open questions updated: OQ-15, OQ-16, OQ-17 (still open). Added: OQ-47 to OQ-50.

> **No legal, tax, payment or contract compliance is established by this phase.** The module stores, versions, approves and applies whatever values IMC enters. No value is entered or seeded, so every financial operation that needs a policy is still blocked (409 `policy_not_configured`) on a fresh installation.

## 1. What was inspected first

- Code and docs: ADR-006, ADR-012, ADR-017, ADR-020, ADR-021; OQ-15, OQ-16, OQ-17, OQ-21, OQ-24, OQ-39, OQ-43, OQ-45, OQ-46.
- Records and services:
  - `Agreement::conclude()` (immutable, in the offer-acceptance transaction);
  - `ContractController` (drafts only, `draft_not_binding`);
  - `InvoiceController` and `Invoice::issue()` (rules from `config('jahez.billing.*')`, i.e. env);
  - `BillingPolicy`, `Money` (integer minor units);
  - `PaymentController` and `PaymentProcessor` (verified gateway evidence only);
  - `InvoicePolicy`, `Role::permissions()` (one `imc_admin` role holding every permission), notifications, the frontend billing workspace and admin pages.
- Undocumented rules that were **not** assumed:
  - a revenue-share rate (DOC §5 and §6 conflict);
  - a tax rate, or whether prices include tax;
  - the invoice issuer and payer;
  - numbering, e-invoicing, due dates, part payments;
  - credit notes, refunds and payouts;
  - contract parties and templates, e-signature validity, contract states beyond draft.

### Technical capability vs owner decision

| Safe to build as a technical capability (built) | Needs explicit owner approval before activation (not decided, nothing entered) |
| --- | --- |
| Policy storage, kinds, scopes, versions, effective dates, state machine | Every value: rates, taxes, fees, inclusive pricing, issuer, numbering, due days, part payments, manual entries, clauses, parties, duration |
| Maker-checker approval, no retroactive approval, no overlap, immutable approved versions | Who holds `financial_policies.manage` / `.approve` / `payments.record` (OQ-49) |
| Resolution by scope and day, share locks, snapshots on records | Scope precedence (OQ-47, PROPOSED), which date governs the revenue share (OQ-48, PROPOSED) |
| Decimal-exact calculation, inclusive/exclusive tax extraction, fees | Whether the calculation method matches Egyptian tax law and ETA e-invoicing (OQ-16) |
| Manual payment entry with evidence, audit and idempotency | Whether manual entries are acceptable proof of payment, and who verifies them (OQ-16, OQ-49) |
| Contract template versions and term snapshots on drafts | The legal effect of any contract, signature, activation, expiry, renewal, termination (OQ-17) |
| Legacy marking of existing records | Retroactive amendments, credit notes, voiding, refunds (OQ-50) |

## 2. Data model and architecture

| Migration | Change |
| --- | --- |
| `2026_10_05_100001` | `financial_policies` (kind, scope_type, scope_id, unique `(kind, scope_key)`) |
| `2026_10_05_100002` | `financial_policy_versions` (version, status, effective_from/to, `parameters` JSON, change reason, preparer, submitter, decider, decision note, superseded_by; unique `(policy, version)`; resolution index) |
| `2026_10_05_100003` | `user_permission_grants` (unique `(user_id, permission)`) |
| `2026_10_05_100004` | `policy_basis` (default `legacy`) and version FKs on `agreements`, `contracts` (+ `terms_snapshot`), `invoices` (+ `type`, `payer`, `fees_amount`, `amount_paid`, `due_date`, `calculation`, index `(status, due_date)`); `payments.method`, `recorded_by_user_id`, `received_on`, `evidence_note` |

All four are reversible. Up, down (4 steps) and up again were run on a scratch MySQL 8.4 database (§6). Version FKs restrict deletes, and versions are never deleted.

Code: everything is in `app/Billing/`.

| Class | Role |
| --- | --- |
| `PolicyParameters` | Per-kind rules and normalisation |
| `PolicyCalendar` | Business day in `JAHEZ_BUSINESS_TIMEZONE` |
| `PolicyContext`, `PolicyResolver` | Scope precedence and share locks |
| `FinancialPolicyLifecycle` | Every state change, authorised, locked, audited |
| `InvoiceCalculator` | Integer arithmetic; inclusive/exclusive taxes; fees; revenue share; due dates |
| `BillingPolicy` | DB-backed; `issuingPolicies()` reports all missing policies at once; `readiness()` gives per-agreement reasons in Arabic |

Models: `FinancialPolicy`, `FinancialPolicyVersion` (immutability guard), `UserPermissionGrant`. Enums: `FinancialPolicyKind`, `FinancialPolicyScope`, `FinancialPolicyVersionStatus`, `PolicyBasis`, `InvoiceStatus::PartiallyPaid`.

Wiring:
- Agreements record the revenue-share version at conclusion, and never block acceptance.
- Contract drafts record the template version and a terms snapshot. They stay non-binding, and the template may raise the trainee minimum.
- Invoices take issuer and payer from the invoicing policy; issuing stores all versions, the breakdown and the due date.
- Payments charge the outstanding amount; part payments are allowed; manual entries are authorised.
- `PolicyNotConfiguredException` now also renders `reason_ar` and `missing_policies`.

## 3. API endpoints and permissions

See [api-endpoints.md](../api-endpoints.md#financial-and-contract-policies-adr-023) and [roles-permissions.md](../roles-permissions.md).

New endpoints:
- `GET|POST /financial-policies`, `GET /financial-policies/{id}`, `POST /financial-policies/{id}/versions`
- `GET|PATCH /financial-policy-versions/{id}` and `POST .../{submit,approve,reject,archive,end,preview}`, `GET .../history`
- `GET /financial-policies/resolve`, `POST /financial-policies/preview`
- `GET /agreements/{id}/financial-readiness`
- `POST /invoices/{id}/manual-payments`

All are under `/api/v1`, with numeric route constraints (ApiVersioningTest passes).

New permissions:
- `financial_policies.view` belongs to the administrator role.
- `financial_policies.manage`, `financial_policies.approve` and `payments.record` are granted per person (`php artisan jahez:permissions`), audited.
- Policies are checked in the Form Requests **and** again inside `FinancialPolicyLifecycle` (tested by calling the service directly).

Frontend:
- **New page:** `/admin/financial-settings` «الإعدادات المالية والتعاقدية». Five tabs: revenue share; invoices and payment; taxes; contract templates; versions, approvals and audit, with a resolver and preview.
- **Updated:** invoice detail (due date, overdue, legacy marker, server breakdown, policy versions, manual payment form); agreement view (server readiness reasons replace the static "no payment terms" notice); contract items (template version, clauses); billing panel; print view.
- **Shared fix:** `Modal` now renders through a portal. Modals opened inside a card, or inside another modal, were clipped; the browser screenshots showed it.

## 4. Versioning and effective-date rules

- Whole days in the business time zone (Africa/Cairo by default). A version may not start before today, at drafting, submission or approval.
- One draft or pending version per policy. Approval by a holder of `financial_policies.approve` who did not draft, edit or submit it.
- Approved versions never change. The only change is an earlier end date: superseded (end = successor's start − 1 day, automatic on approval) or ended early (last day ≥ today). Any other overlap is a 409.
- Scheduled versions can be withdrawn (archived), except one that superseded another.
- Records keep the version ids and values they were made with. Nothing is recalculated. Retroactive amendment is not possible (OQ-50).

## 5. Migration and legacy-data strategy

- Every pre-existing agreement, contract and invoice is `policy_basis = legacy` through the column default. No version or value is invented for them, and nothing is recalculated.
- A legacy **draft** invoice cannot be issued: it is cancelled and drafted again under the policies. A legacy **issued** invoice keeps its stored values and cannot take a manual entry.
- The four ADR-017 env variables are no longer read. `app:check-production` fails while any is set, and validates `JAHEZ_BUSINESS_TIMEZONE`.
- **No policy is seeded**, in any environment. `LocalDemoSeeder` (local/testing only) adds two demo finance administrators with grants, and no policy.

## 6. Tests and commands actually run (2026-10-04)

| Check | Result |
| --- | --- |
| Baseline `DB_PORT=3307 php artisan test --compact --parallel --processes=4` (MySQL 8.4.9), before any change | **1141 passed** (4037 assertions), 118.70 s |
| Migrations `migrate` → `migrate:rollback --step=4` → `migrate` on scratch DB `jahez_migcheck` (3307) | all DONE, no error |
| Full suite on MySQL 8.4 after the change, first run | 3 failed, 1253 passed. All three were intended consequences, and the tests were updated (not weakened): `/me` no longer lists the per-person permissions for the role; the offer acceptance also share-locks `financial_policies`; the demo seeder adds two finance accounts |
| Full suite on MySQL 8.4 (final, after the last code fix) | **1258 passed** (4650 assertions), 139.16 s, 0 failed |
| Full suite on a **disposable** MariaDB 10.4.32 (port 3308, scratch data directory, deleted afterwards) | **1258 passed** (4650 assertions), 117.07 s |
| `composer analyse` (Larastan level 8) | first run 8 errors (fixed at the cause); final **No errors** |
| `vendor/bin/pint --dirty --format agent` and `pint --test` | passed |
| Frontend `npx tsc -b` | clean |
| Frontend `npx oxlint` | exit 0, no findings |
| Frontend `npm test` (new: `node --test`, no new dependency) | **12 passed, 0 failed** |
| Frontend `npm run build` | built; the existing warning that a chunk exceeds 500 kB remains |
| Postman (`postman/run-collection.mjs`, 400 ms delay) against `php -S` on 8010 with isolated DB `jahez_postman` (3307, migrated and seeded fresh) | **796 assertions passed, 0 failed**. Run twice on fresh databases, the second after the Modal fix |
| Browser: headless Edge driven over the DevTools protocol (`scratchpad/e2e.mjs`, no dependency) against a build pointing at the isolated API | **18/18 checks passed**. The first run had 1 failure caused by the script checking before a refetch finished; fixed with a wait and rerun on a fresh database |

New test files:
- `tests/Feature/Api/V1/FinancialPolicyTest.php` (54)
- `tests/Feature/Api/V1/FinancialPolicyBillingTest.php` (25)
- `tests/Unit/InvoiceCalculatorTest.php` (9)
- `tests/Feature/Policies/FinancialPolicyPolicyTest.php` (20)
- `tests/Feature/Console/ManagePermissionGrantsTest.php`
- 2 new cases in `AuditTrailTest`
- `front/tests/financialPolicies.test.ts` (12)

Updated: `InvoiceTest`, `PaymentTest`, `InvoicePolicyTest`, `AgreementAndContractTest`, `NegotiationTest`, `CurrentUserTest`, `ReferenceDataSeederTest`, `CheckProductionConfigurationTest`. Billing fixtures now come from `configureBilling()`, which writes approved policy versions; no application default exists.

Coverage of the brief's §10:
- authorised and unauthorised management;
- approval separation, including inside the service;
- invalid transitions;
- effective-date and scope resolution;
- overlap prevention (submit and approve);
- decimal calculations (exclusive, inclusive, multiple taxes, fees, boundaries, largest amounts);
- historical snapshots after later policy changes;
- missing policies blocking drafting and issuing, with Arabic reasons;
- invoice lifecycle (part payment, paid, overdue by business day, numbering format);
- contract template version preservation;
- cross-organisation 404s and IDOR on readiness and manual payments;
- lock order for approval and issuing (`lockingReads`, the project's concurrency pattern) and the DB unique constraint;
- legacy records;
- frontend validation and Arabic error states (node tests and browser);
- full integration from approved agreement to contract draft, issued invoice and recorded payment.

**Not done:** a true two-connection race test. Concurrency is covered by the lock-order assertions and constraints, as elsewhere in the project.

## 7. Business and legal decisions still needed

- OQ-15: the revenue-share rate(s), base and level; payouts.
- OQ-16: issuer and payer, numbering and ETA e-invoicing, VAT and withholding, inclusive pricing, due dates, part payments, manual payment evidence, gateway, refunds, credit notes, billing schedules, currency.
- OQ-17: contract parties (is IMC one?), template text, e-signature and its legal validity, contract states after draft.
- OQ-47: scope precedence. OQ-48: date basis of the revenue share. OQ-49: who holds the grants. OQ-50: retroactive amendments and credit notes.
- OQ-21: department roles; the per-person grants are the interim.

## 8. Intentionally blocked pending those decisions

- Every invoice operation until approved invoicing, tax and payment-terms policies exist.
- Any revenue-share figure until a share policy is approved; no payout ever.
- Contract signature, approval, activation, expiry, renewal and termination: not modelled.
- Gateway payments: no adapter. Refund initiation, payouts, credit notes, voiding issued invoices, retroactive amendments: not built.

## 9. Final status table

| 1. Implemented and verified | 2. Configurable, awaiting policy approval | 3. Still blocked by legal or financial decisions | 4. Needs external payment or signature integration |
| --- | --- | --- | --- |
| Policy kinds, scopes, versions, effective dates, statuses | Revenue-share rate per scope (OQ-15) | Payouts of the revenue share (OQ-15, OQ-16) | Payment gateway adapter and credentials (OQ-16) |
| Maker-checker approval; per-person grants; audit history | Invoice issuer, payer, numbering format, manual entries (OQ-16) | Credit notes, voiding issued invoices, refunds (OQ-50, OQ-16) | Gateway refunds and reconciliation beyond the existing boundary |
| No overlap, no retroactive approval, immutable approved versions | Taxes, fees, inclusive pricing (OQ-16) | ETA e-invoicing and statutory tax compliance (OQ-16) | Electronic signature provider and its legal validity (OQ-17) |
| Scope resolution with share locks; Arabic blocked reasons | Due days and part payments (OQ-16) | Contract legal effect, signature, activation, expiry, renewal, termination (OQ-17) | ETA e-invoicing integration, if required (OQ-16) |
| Decimal-exact calculation and stored breakdown | Contract templates: clauses, parties, duration, trainee minimum (OQ-17) | Retroactive policy amendments (OQ-50) | — |
| Snapshots on agreements, contract drafts, invoices; legacy marking | Scope precedence and date basis, now PROPOSED (OQ-47, OQ-48) | Billing schedules and instalments (OQ-16) | — |
| Partial and manual payments with evidence | Who holds the permissions (OQ-49) | — | — |
| Admin UI «الإعدادات المالية والتعاقدية»; readiness and breakdown in portals | — | — | — |

## 10. Security, performance, risks

- **Security:**
  - Approval and payment recording are per-person grants, checked twice (Form Request and lifecycle service).
  - IDs and statuses from clients are never trusted.
  - Members get 403/404.
  - Policy history omits IP and e-mail.
  - Manual entries need Idempotency-Key and a unique reference; amounts are never written to the audit log.
- **Performance:**
  - Resolution is 2 queries plus the factory's sectors, using the composite resolution index.
  - Issuing takes 4 share locks on policy rows; approval locks one policy row.
  - Not measured on staging.
- **Risks:**
  - The local dev MariaDB on 3306 is still in recovery mode (unrelated to this phase; see troubleshooting/memory). These migrations have not been applied to it.
  - `php artisan serve` with shell `DB_*` overrides silently serves `.env` (documented in troubleshooting).
