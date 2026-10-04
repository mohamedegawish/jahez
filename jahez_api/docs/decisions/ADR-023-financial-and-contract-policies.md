# ADR-023: Database-managed, versioned financial and contract policies

- **Status:** Accepted (2026-10-04) as a **technical capability**. Every value a policy holds (rates, taxes, fees, issuer, numbering, due dates, contract clauses) is still an owner decision (OQ-15, OQ-16, OQ-17). No policy and no value is seeded. Supersedes the environment settings of [ADR-017](ADR-017-agreements-contracts-billing.md) for billing rules.
- **Decided by:** Owner brief "Dynamic Financial Policies, Billing & Contract Management" (2026-10-04): replace hard-coded or permanently blocked financial and contract behaviour with configurable, versioned, administratively managed policies; never assume a rate, tax, issuer, due date or legal effect.

## Context

ADR-017 isolated each undecided billing rule behind an environment variable (`JAHEZ_INVOICE_ISSUER`, `JAHEZ_INVOICE_NUMBER_PREFIX`, `JAHEZ_TAX_RATE_PERCENT`, `JAHEZ_REVENUE_SHARE_PERCENT`). That kept unapproved numbers out of the code. But the rules belong to the ministry, and an environment variable has several problems:
- Changing it needs a deployment.
- It has no approval, history or effective date.
- It cannot differ by service, sector or provider.
- Records made under it do not say which value they used.

## Decision

### Policies and versions
- `financial_policies`: one row per kind and scope; unique `(kind, scope_key)`.
  - **Kinds:** `revenue_share`, `tax` (taxes and fees), `invoicing` (issuer, payer, currency, numbering, invoice types, manual payments, whether a revenue share is required), `payment_terms` (due rule, part payments), `contract_template` (title, parties, duration, knowledge-transfer minimum, clauses).
  - **Scopes:** `global`, `sector`, `catalog_service`, `service_provider`. Each kind allows only some of them (`FinancialPolicyKind::allowedScopes()`); invoicing is global only.
- `financial_policy_versions` hold the values (`parameters`, validated per kind by `App\Billing\PolicyParameters`) and the effective period (`effective_from`, optional `effective_to`, whole days in the business time zone `JAHEZ_BUSINESS_TIMEZONE`, default Africa/Cairo).
  - Every value is required and has **no default**. Even a yes/no rule must be answered.
  - Where only one method exists (a percentage share on the subtotal before tax; due dates counted in days after issue), the method is still stored, so a later method is an addition.

### Lifecycle (`App\Billing\FinancialPolicyLifecycle`)
- Statuses:
  - `draft` → `pending_approval` → `approved` | `rejected`
  - `rejected` or `draft` → `archived`
  - `approved` → `superseded` | `ended` | `archived` (archive only before it starts)
  - `ended` → `superseded`
- Whether an approved version is `scheduled`, `active` or `expired` is computed from its dates, so no job changes statuses.
- **One open (draft or pending) version per policy.**
- **Approval:**
  - needs `financial_policies.approve`;
  - the approver may not have drafted, edited or submitted the version;
  - the start date must not have passed: nothing is approved retroactively.
- **No overlap:** the approved versions of a policy never overlap. A new version supersedes the version in effect on its start date (that version's `effective_to` becomes the day before); any other overlap is refused with 409, both when the version is submitted and, under the policy row lock, when it is approved.
- **Immutability:** an approved version's values and dates never change. The model refuses any other update, and refuses to move an end date later.
  - The only later change is an earlier end date: when the version is superseded, or ended early by an approver.
  - A scheduled version that superseded another cannot be withdrawn, because the earlier version's end would have to move later.
- **Audit:** every step is recorded inside its transaction (ADR-012). Edits record each changed field from/to; contract clauses are recorded as counts.

### Resolution (`App\Billing\PolicyResolver`)
- For a kind, a context (catalog service, the factory's sectors, the service provider) and a day, the candidates are the approved, superseded or ended versions that cover the day.
- **The most specific scope wins**: provider, then service, then sector, then global (**PROPOSED, OQ-47**). Two sector policies that both apply are not chosen between: the operation is refused (409, `decision_needed: OQ-47`).
- Inside a transaction that creates a record, the candidate policy rows are **share-locked**. Approval locks the policy row for update, so a record never references a version superseded while it was being made.

### Use in records (snapshots)
- **Agreements** record the revenue-share version in effect when the offer is accepted (`revenue_share_policy_version_id`). Acceptance is never blocked by it.
- **Contract drafts** record the contract template version in effect (if any) and `terms_snapshot`: agreed offer and price, parties, policy versions, template clauses, and `legal_status: draft_not_binding`.
  - Without an approved template a draft is generated exactly as before.
  - A template may raise, never lower, the DOC §6 knowledge-transfer minimum.
  - Contracts stay drafts: no signature, approval or activation state (OQ-17).
- **Invoices:**
  - **Drafting** needs an approved invoicing policy, which decides the issuer and payer; its version is stored.
  - **Issuing** needs approved invoicing, tax and payment-terms policies, plus a revenue share when the invoicing policy requires one. Every missing policy is reported at once.
  - Issuing stores the number (the policy's prefix and padding, gap-free), the fees, taxes, total, due date, informational revenue share, the four version references and the whole calculation (`calculation` JSON).
  - Nothing is recalculated later. Overdue is computed from the due date, not stored.
- **Money:**
  - Integer minor units throughout (`App\Billing\Money`, `App\Billing\InvoiceCalculator`); half-up rounding once per component.
  - For tax-inclusive prices the net is extracted once, and the last tax takes the rounding remainder, so the parts always add up.
  - `DECIMAL(14,2)` columns; decimal strings in the API, also for percentages; JSON numbers are refused.
- **Payments:**
  - A gateway payment is for the amount outstanding.
  - An invoice becomes `partially_paid` or `paid` only from verified gateway evidence or an **authorised manual entry** (`POST /invoices/{id}/manual-payments`). A manual entry:
    - needs `payments.record` and an Idempotency-Key, plus a unique evidence reference;
    - is allowed only when the invoicing policy the invoice was issued under permits manual entries;
    - may be at most the amount outstanding, and less only when the payment terms allow part payments;
    - is audited without the amount.
  - No gateway is integrated.

### Legacy records
- `policy_basis` (`legacy` | `policy`) on agreements, contracts and invoices. The migration default `legacy` marks every existing row without inventing anything; new rows are `policy`.
- Legacy records reference no version and are never recalculated.
- A legacy draft invoice cannot be issued (cancel and redraft). A legacy issued invoice keeps the values the old environment settings gave it, and cannot take a manual entry.

### Permissions
- `financial_policies.view` belongs to the IMC administrator role.
- `financial_policies.manage`, `financial_policies.approve` and `payments.record` belong to **no role**. They are granted to individual active IMC administrators (`user_permission_grants`) from the console, with a reason, audited: `php artisan jahez:permissions grant|revoke|list`.
- This is the separation of duties the single `imc_admin` role (OQ-21) could not give. No administrator can grant these to themselves through the API.
- `contracts.manage` / `contracts.approve` were **not** added: contract approval and signature are not decided (OQ-17), and drafting stays with the parties. `invoices.view_any` and `invoices.manage` keep their meaning.

### Environment
- The four ADR-017 billing variables are no longer read. `app:check-production` fails while any of them is still set.
- `JAHEZ_PAYMENT_GATEWAY` stays: it is a technical integration with credentials, not a business rule.

## Consequences

- The ministry changes rules from «الإعدادات المالية والتعاقدية» without a deployment. Each change is a new approved version from a future or current date; history stays reproducible.
- Every financial operation stays blocked until the owner's values are entered and approved by two different people. The module does not establish legal, tax, payment or contract compliance.
- Existing tests create their own approved fixtures (`configureBilling()`, `approvedPolicyVersion()` in `tests/Pest.php`); no default policy exists in the application or the seeders.
- Open: scope precedence (OQ-47), which date governs the revenue share (OQ-48), who holds the grants (OQ-49), retroactive amendments and credit notes (OQ-50).
