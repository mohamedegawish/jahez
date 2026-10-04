# ADR-017: Agreements, contract drafts, billing and payment boundaries

- **Status:** Accepted (2026-10-03). **The billing rules below are no longer environment settings:** since 2026-10-04 they are approved, versioned database policies ([ADR-023](ADR-023-financial-and-contract-policies.md)). The records, states, money arithmetic and payment boundary described here still apply, with the ADR-023 additions (`partially_paid`, manual payment entries, due dates, policy snapshots). The structures are accepted; every commercial and legal rule they need stays with the owner (OQ-15, OQ-16, OQ-17, OQ-39).
- **Decided by:** Project owner instruction of 2026-10-03: "complete the financial domain as far as the source requirements allow, not to fabricate financial policy"; isolate each unresolved rule behind configuration and return an explicit blocked result. The schema and boundaries below are technical.

## Context

DOC §5 describes a revenue share in concept only and gives conflicting figures (§5 vs §6). DOC §6 requires a knowledge-transfer commitment "attached to the contract". Nothing in the sources defines contract parties, templates, signatures, invoice issuers, numbering, taxes, payment flows, a gateway, refunds or payouts. Until Phase 7, accepting an offer created no record beyond the thread status.

## Decision

Four concepts are kept separate, each with its own record and lifecycle, so none is mistaken for another:

| Step | Record | Created by | Binding or financial effect |
| --- | --- | --- | --- |
| Offer acceptance | `provider_requests.status = agreed` | the factory | none |
| Agreement | `agreements` (immutable) | the system, in the acceptance transaction | none: a record of the accepted offer version and its price |
| Contract | `contracts` (drafts, versioned) | either party of the agreement | **none**: always `draft_not_binding`; no signature, approval or execution state (OQ-17 interim) |
| Invoice, payment | see the billing and payment sections below | — | only once the owner configures the policies |

### Agreements
- Created by `Agreement::conclude()` inside the offer-acceptance transaction, after the thread becomes `agreed`; audited (`agreement.concluded`), with no price in the audit log.
- One per agreed thread (unique `provider_request_id` and `offer_id`). The terms are read from the accepted offer version, which is append-only; the price is copied for billing.
- **Visibility:** both parties see the terms and price. IMC administrators (`agreements.view_any`) see that the agreement exists and its contract status, never its terms (PROPOSED, consistent with OQ-39).
- Threads agreed before this ADR were back-filled by the migration, with no concluding user.

### Contract drafts
- **Drafting:** either party drafts a contract for its agreement (PROPOSED). The agreement row is locked, so only one draft is in force at a time; another attempt gets 409. A cancelled draft is kept, and the next draft is the next version.
- **Knowledge transfer:** each draft must carry the knowledge-transfer commitment of DOC §6: at least **two** IMC engineers trained (source rule) and a detailed training plan.
  - The legal commitment memo "attached to the contract" needs document uploads, which do not exist (OQ-10). It is reported as `not_available`.
- **States:** `draft` → `cancelled` only. Signature, approval, activation, completion and termination are **not modelled**: the sources define none. The resource reports `binding: false`, `legal_status: draft_not_binding` and `signature.status: not_available`.

## Consequences

- A client can show the whole path from request to agreement to contract draft without implying anything binding.
- When the owner answers OQ-17, signature and activation states are added to `ContractStatus::canBecome()`, and the draft stays the first state.
- Billing and payments build on the agreement (its price and currency), not on the offer or the thread.

## Billing (invoices)

- **Policy boundary:** `config/jahez.php` `billing` holds the owner's rules. `App\Billing\BillingPolicy` reads each one, or refuses the operation with **409 `policy_not_configured`** and the open question (`decision_needed`). Nothing has a default.

  | Rule | Setting | Needed to | Open question |
  | --- | --- | --- | --- |
  | Who issues invoices | `JAHEZ_INVOICE_ISSUER` (`imc` or `service_provider`) | draft | OQ-16 |
  | Invoice numbering | `JAHEZ_INVOICE_NUMBER_PREFIX` (then a gap-free sequence: `JZ-000001`) | issue | OQ-16 |
  | Tax on the subtotal | `JAHEZ_TAX_RATE_PERCENT` (`0` is a decision too) | issue | OQ-16 |
  | IMC revenue share | `JAHEZ_REVENUE_SHARE_PERCENT` | shown on issued invoices; **informational, nothing is paid out** | OQ-15 |

  `GET /billing/configuration` reports which operations are available.
- **Drafts:** the configured issuer drafts the agreement's invoice. The first line is the agreed service (its catalog name) at the agreed price. The issuer may add or remove lines on the draft; the platform adds no fee, tax or share line of its own.
  - At most one invoice per agreement that is not cancelled: no billing schedule (instalments) is decided.
- **Issuing** fixes the number, the tax (half up on the subtotal), the total and the informational revenue share, and freezes the lines. The number comes from `invoice_number_sequences`, whose row is locked, so numbers are gap-free and unique under concurrency.
- **Money:**
  - `DECIMAL(14,2)` columns and decimal strings in the API.
  - Integer minor-unit arithmetic (`App\Billing\Money`), never floats.
  - Whole-number quantities, so no unit-rounding rule is needed.
  - A total beyond what `DECIMAL(14,2)` holds is refused with 422.
- **States:** `draft` → `issued` | `cancelled`; `issued` → `paid`; `paid` → `refunded`.
  - `paid` and `refunded` follow verified payment evidence only.
  - An issued invoice cannot be cancelled or voided until credit notes are decided (409 `policy_not_configured`).
- **Visibility:** both parties of the agreement see the invoice. IMC administrators with `invoices.view_any` see every invoice (PROPOSED billing oversight; the owner should confirm it with OQ-39).

## Payments

- **Gateway boundary:** `App\Billing\PaymentGateway` (initiate, verify and normalise a callback, query a status). No adapter ships with the application, because no gateway is chosen (OQ-16). `JAHEZ_PAYMENT_GATEWAY` names an adapter registered in `jahez.billing.gateways`. Without one, starting a payment is refused with 409 `policy_not_configured`, and every callback URL is 404. The test suite uses a fake adapter under `tests/` only.
- **Starting a payment:**
  - The factory starts it with a required `Idempotency-Key` header: the same key returns the same payment, never a second charge.
  - The invoice row is locked while it is created.
  - Only one payment may be pending or succeeded per invoice.
  - The gateway is called after the commit, outside the lock. If the call fails, the payment is marked `failed` and the API answers 503.
- **Only verified evidence changes a payment** (`App\Billing\PaymentProcessor`): a gateway callback whose signature the adapter verified, or the gateway's status query (`php artisan payments:reconcile`, scheduled every 15 minutes, for lost callbacks).
  - Each event is recorded once in `payment_events`, unique per gateway and event id, before anything changes. A repeated callback is a `duplicate` and is applied at most once.
  - An amount or currency that differs from the payment's (`amount_mismatch`), an unknown reference, an unchanged status and an impossible transition are recorded and change nothing.
  - Locks: the invoice, then the payment.
- **Payment states:** `pending` → `succeeded` | `failed` | `cancelled`; `succeeded` → `refunded` (as the gateway reports it). Starting a refund, and payouts to providers, are **not built**: refund policy and money flows are undecided (OQ-16, OQ-15).
- **Audit:** `invoice.drafted`, `invoice.issued` (number only), `invoice.cancelled`, `payment.initiated` and `payment.status_changed` (actor null for gateway evidence). Amounts are never written to the audit log.

## Placement

The billing services (`Money`, `BillingPolicy`, the gateway contract, `PaymentGatewayManager`, `PaymentProcessor`) are in a new `app/Billing` folder. `AGENTS.md` asks for approval before a new base folder; the owner's instruction of 2026-10-03 to build this policy boundary and gateway interface is that approval. The folder can be renamed without changing behaviour.
