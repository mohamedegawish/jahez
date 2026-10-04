# Phase 7: Agreements, Contracts, Billing and Payments

**Date:** 2026-10-03 · **Branch:** `phase/04-07-marketplace`

**Result:** the domain boundaries are **built and tested** (agreements, contract drafts, invoices, payments). **Every money operation is BLOCKED** until the owner decides the rules in §3. Nothing is calculated, issued or charged by default.

## 1. History

- **First pass (commit `e76a36b`):** nothing financial was built, and the phase was reported BLOCKED: the sources define no revenue-share rule, money flow, contract, invoice issuer, tax or gateway.
- **Owner instruction (2026-10-03):** "complete the financial domain as far as the source requirements allow, not to fabricate financial policy." Build the independent structures and isolate each unresolved rule behind configuration with an explicit blocked result. This is [ADR-017](../decisions/ADR-017-agreements-contracts-billing.md).

## 2. What exists now

| Concept | State | Blocked by |
| --- | --- | --- |
| **Agreement** | Created in the same transaction as an offer acceptance; immutable; the price copied from the accepted offer version. Not a contract. | — |
| **Contract** | Drafts only, never binding: version history, cancel, and the DOC §6 knowledge-transfer commitment (at least two IMC engineers, a training plan). No signature, approval or execution state. | Signing and activation: OQ-17. Memo attachment: OQ-10. |
| **Invoice** | Draft (lines in exact minor units), issue (gap-free number, tax half up, total), cancel a draft. `paid` and `refunded` come from verified payments only. | Drafting needs the issuer; issuing needs numbering and tax: **OQ-16**. Voiding an issued invoice needs credit notes: OQ-16. |
| **Revenue share** | Shown on an issued invoice once a rate is set; informational. | **OQ-15**. Payouts: not built. |
| **Payment** | Started with `Idempotency-Key`. Only verified gateway evidence (signed callback or status query) changes it. Each gateway event is applied at most once. Reconciliation runs every 15 minutes. | No gateway is chosen and no adapter ships: **OQ-16**. Starting refunds: not built (OQ-16). |

**What the API answers today:** with every setting unset, drafting, issuing and paying get **409** `{"code": "policy_not_configured", "decision_needed": "OQ-16"}`. `GET /api/v1/billing/configuration` lists the available operations. Agreements and contract drafts work.

**Guards:**
- `NegotiationTest` › "records the agreement, and creates no contract, invoice or payment": accepting an offer creates only the agreement.
- `InvoiceTest` and `PaymentTest`: every unset rule refuses its operation.
- The audit log stores no amounts (`AuditTrailTest`).
- A payment never succeeds without signed evidence: a forged signature gets 400, and a mismatched amount or currency changes nothing (`PaymentTest`).

## 3. Decisions that unblock the money operations

Each operation needs the setting in the right-hand column, set from an owner decision recorded in the open question:

| # | Decision | Unblocks | Setting |
| --- | --- | --- | --- |
| 1 | **Revenue share** (OQ-15): the rule (DOC §5 gives 20–30%, DOC §6 5–20%, and §5 recommends a variable share), its base, and who approves it | the share shown on invoices; later, payouts | `JAHEZ_REVENUE_SHARE_PERCENT` (a single rate; a variable rule needs a new policy implementation) |
| 2 | **Who issues invoices and to whom** (OQ-16): factory → IMC → provider, or factory → provider with an IMC commission | drafting invoices | `JAHEZ_INVOICE_ISSUER` |
| 3 | **Invoice numbering**, and whether invoices go through the Egyptian Tax Authority's e-invoicing system | issuing | `JAHEZ_INVOICE_NUMBER_PREFIX` (an e-invoicing integration would need more) |
| 4 | **Taxes:** VAT and withholding, rates, and who charges them | issuing | `JAHEZ_TAX_RATE_PERCENT` |
| 5 | **Payment gateway:** provider, methods, settlement account, credentials, PCI scope | payments | an adapter class plus `JAHEZ_PAYMENT_GATEWAY` (a dependency approval if the gateway has an SDK) |
| 6 | **Billing schedule** (instalments?) | more than one invoice per agreement | not yet a setting |
| 7 | **Credit notes, voiding, refunds** | cancelling issued invoices; starting refunds | not built |
| 8 | **Payouts to providers** | provider payments | not built |
| 9 | **Contracts** (OQ-17): parties, templates, e-signature | binding contracts | not built |
| 10 | **Retention of financial records** (OQ-25) | purging | not built |

## 4. Verification

The suite results for this delivery are recorded in the Phase 4–7 completion section of the [Phase 6 log](phase-06-requests-offers-negotiation.md#9-business-logic-completion-2026-10-03). For billing and payments specifically:
- **`InvoiceTest`:** drafting by the configured issuer only; exact subtotals; tax half up (1000.05 × 14% = 140.01); gap-free numbers (`JZ-000001`, `JZ-000002`); the bound on totals; the lock order (invoice, then number sequence); each unset rule refusing its operation.
- **`PaymentTest`:** an idempotent start (one gateway call); 503 marking the payment failed; signed callbacks; duplicate, unknown, mismatch and impossible-transition outcomes; refunds; reconciliation; the lock order (invoice, then payment).
- **`Unit/MoneyTest`:** conversions, half-up percentages, percentage parsing.
- **`Policies/InvoicePolicyTest`.**

## 5. Exit gate

| Criterion | Evidence | Result |
| --- | --- | --- |
| Financial rules approved (OQ-15, OQ-16, OQ-17) | All open | **BLOCKED** |
| No financial rule invented; every money operation refused until configured | `policy_not_configured` tests; no defaults in `config/jahez.php` | PASS |
| Independent structures built and tested (agreements, contract drafts, invoices, payments, gateway boundary) | §2, §4 | PASS |

**Gate: BLOCKED for money operations; boundaries complete.**
