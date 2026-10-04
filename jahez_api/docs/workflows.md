# Workflows

State machines the API enforces. Clients never set a status field: each change is an explicit action, and the server checks who asks (policies) and the current status. Any other change gets **409** with the envelope's `conflict` code. Labels:
- **OWNER-APPROVED:** the owner decided.
- **PROPOSED:** a minimal technical design, pending owner confirmation.

## 1. Provider approval (OWNER-APPROVED: approval before visibility; ADR-014)

| From | Action (IMC, `service_providers.approve`) | To | Reason |
| --- | --- | --- | --- |
| `pending` | approve | `approved` | optional |
| `pending` | reject | `rejected` | **required** |
| `pending` | ask for corrections (ADR-021) | `changes_requested` | **required** |
| `changes_requested` | approve / reject | `approved` / `rejected` | optional / **required** |
| `approved` | suspend | `suspended` | **required** |
| `rejected`, `suspended` | approve | `approved` | optional |
| `rejected`, `changes_requested` | **the provider's own member** asks for a new review (`/review-request`, PROPOSED) | `pending` | optional note |

**Factories (ADR-021, consequences PROPOSED, OQ-46)** follow the same table through `POST /factories/{id}/approval` (`factories.approve`) and `POST /factories/{id}/review-request`. The decision never touches the readiness assessment. With `JAHEZ_FACTORY_APPROVAL_REQUIRED` on, only an approved factory sends requests.

**Service listings (ADR-021)**, per provider and catalog service, through `POST /service-providers/{id}/services/{catalogService}/review` (`service_listings.review`): `pending → approved | rejected`, `approved → suspended`, `rejected | suspended → approved`; reject and suspend need a reason. The provider's own members send a `rejected` listing back with `POST …/services/{catalogService}/resubmit` (ADR-022): `rejected → pending`, never approved automatically; 409 from any other status. A factory sees a listing only when the listing and its provider are both approved and the provider is eligible for the factory; a promotion only reorders such listings.

Approving a provider, and asking for a new review, first checks the profile fields the owner made required ([OQ-36](open-questions.md#oq-36); none by default). Only `approved` providers appear to factories. A profile edit does not change the status (owner decision). Suspending a provider **pauses** its open threads (see the negotiation section below).

## 2. Marketplace request (model OWNER-APPROVED; states PROPOSED, OQ-38; ADR-015)

The confirmed flow:
1. A factory browses eligible providers.
2. It sends **one** request to one or several of them.
3. Each provider independently **accepts** (opens a private negotiation) or **declines**.
4. The two sides **negotiate** by messages and offer versions.
5. The factory **accepts one provider's latest offer**.

Accepting an offer creates **no contract, invoice or payment** (OQ-17, OQ-15, OQ-16).

### Service request (`service_requests.status`)

| From | Action | Actor | To | Effect |
| --- | --- | --- | --- | --- |
| — | create | factory member | `open` | One `pending` provider request per chosen provider; each must be approved, offer the service and target one of the factory's sectors |
| `open` | cancel | requesting factory | `cancelled` | Every `pending`/`accepted` thread becomes `closed` (reason `request_cancelled`) |
| `open` | accept an offer (see below) | requesting factory | `awarded` | With the single-award rule (default) the other `pending`/`accepted` threads become `closed` (reason `request_awarded`); without it they continue, and further offers can be accepted while the request is `awarded` (`JAHEZ_MARKETPLACE_SINGLE_AWARD`, OQ-38) |
| `open` | add providers (PROPOSED) | requesting factory | `open` | One new `pending` thread per added eligible provider; at most 20 providers per request; a provider already on the request cannot be added again |

`awarded` and `cancelled` are final (`ServiceRequestStatus::canBecome`).

### Provider request (`provider_requests.status`): one per provider

| From | Action | Actor | To |
| --- | --- | --- | --- |
| `pending` | accept | the provider | `accepted`: messages and offers open |
| `pending`, `accepted` | decline (optional reason) | the provider | `declined` |
| `pending`, `accepted` | withdraw (optional reason) | the requesting factory | `withdrawn` |
| `accepted` | accept its **latest** offer | the requesting factory | `agreed` |
| `pending`, `accepted` | the request is cancelled or awarded elsewhere | system | `closed` |

`declined`, `withdrawn`, `agreed` and `closed` are final.

### Negotiation inside an `accepted` thread

- **Messages:** both parties may post while the thread is `accepted`; otherwise 409. Posting a message or an offer locks only that thread; actions that change the request (cancel, accept an offer) lock the request first, then the threads. Messages are not offers. Both sides read them; competitors get 404 and IMC gets 403.
- **Offers:**
  - The provider submits versions 1, 2, 3…, each with `based_on_version` set to the latest version it saw (`null` for the first). A stale or repeated submission gets 409.
  - Versions are never edited.
  - The factory can accept only the latest version, while the request is `open`.
  - The factory does not author offers; it negotiates by message. Counter-offers are not modelled (PROPOSED).
  - A version may carry `valid_until`, the last day it may be accepted, set by the provider. The platform sets no validity period of its own.
  - Each version's `state`: `current` (the latest, while the thread is `accepted`), `expired` (the latest, after its `valid_until` day), `lapsed` (the latest, once the thread has ended without agreement), `superseded` (an older version) or `accepted`.
- **History:** every thread status change is recorded with its actor and reason, and both parties can read it (`/provider-requests/{id}/history`).
- **While IMC has suspended the provider** (PROPOSED, [OQ-40](open-questions.md#oq-40)): the thread is paused, not closed. Accepting the request, messages, offers and accepting an offer get 409. The provider can still decline, and the factory can still withdraw or cancel. When IMC approves the provider again, the thread resumes.

### Decisions the owner should confirm (OQ-38)

1. May a factory accept offers from **more than one** provider for the same request? Currently no: the first acceptance awards the request.
2. May a provider **withdraw an offer** without declining the whole thread? Currently it can only decline, or submit a new version.
3. Should requests or offers **expire** after a period? There is no expiry now.
4. Should the factory be able to **reopen** a cancelled request, or **add providers** to an open one? Neither is possible now.
5. A request whose threads have all been declined or withdrawn stays `open`. The factory can now add providers (PROPOSED) or cancel; `active_provider_count` shows 0. Should it close automatically instead?

## 2a. Agreement and contract draft (ADR-017)

Accepting an offer records an **agreement** in the same transaction: an immutable record of the accepted offer version and its price. It is not a contract, an invoice or a payment.

| From | Action | Actor | To |
| --- | --- | --- | --- |
| — | draft a contract for the agreement (knowledge transfer: at least 2 IMC engineers and a training plan, DOC §6) | either party (PROPOSED) | `draft` (version n; only one draft in force) |
| `draft` | cancel (optional reason) | either party | `cancelled` (kept; a new draft becomes version n+1) |

Every contract is a draft and **not legally binding** (OQ-17 interim). Signing, approval, activation and termination are not modelled until the owner decides the parties, templates and e-signature.

## 2b. Invoice and payment (ADR-017)

Each step needs the owner-configured rule it depends on, or gets 409 `policy_not_configured` (OQ-15, OQ-16).

| Invoice from | Action | Actor | To |
| --- | --- | --- | --- |
| — | draft for an agreement (needs the issuer rule) | configured issuer | `draft` |
| `draft` | add or remove lines | issuer | `draft` |
| `draft` | issue (needs numbering and tax rules) | issuer | `issued` |
| `draft` | cancel | issuer | `cancelled` |
| `issued` | a payment succeeds (verified gateway evidence) | system | `paid` |
| `paid` | the payment is refunded (verified gateway evidence) | system | `refunded` |

An issued invoice cannot be cancelled or voided until credit notes are decided.

| Payment from | Evidence | To |
| --- | --- | --- |
| — | the factory starts it (`Idempotency-Key`) | `pending` |
| `pending` | the gateway reports success, failure or cancellation | `succeeded`, `failed`, `cancelled` |
| `succeeded` | the gateway reports a refund | `refunded` |

Starting refunds and paying providers out are not built (OQ-15, OQ-16).

## 3. Factory classification: digital readiness assessment (SOURCE-REQUIRED, RDA; ADR-018)

There is no state machine, and no draft:
1. A factory member loads the current questionnaire (`GET /readiness-questionnaire`).
2. The member submits one choice per question, in a single request.
3. The server, in one transaction:
   - sums the stored points of the chosen choices (10–40);
   - picks the category whose range contains the total (B4 Automation 10–17, Basic 18–25, Advanced 26–33, Smart 34–40);
   - stores the assessment, its answers and the audit entry.

   A failure leaves nothing stored.
4. The response gives the total, the category, the score per pillar and the category's roadmap (focus, steps, recommended services).

**Records:** each assessment is an append-only record of the questionnaire version answered, with its total, category and answer points as submitted.

**Current classification:** the latest completed assessment, by `completed_at` and then id.

**History:** earlier assessments stay readable. A new questionnaire version never changes them.

**Marketplace link:** the recommended services are a guide. `filter[recommended]` on the provider directory narrows the **eligible** providers (approved, in one of the factory's sectors) to those offering a recommended service. Eligibility itself is unchanged.

**Legacy:** manual IMC classifications (ADR-016, superseded) are read-only history at `GET /factories/{id}/assessments`; they never set the current classification.

## 2c. IMC review of an agreement (ADR-020; aftermath PROPOSED, OQ-43)

| From | Action | Actor | To |
| --- | --- | --- | --- |
| `pending` (no decision) | approve | IMC (`agreements.review`) | `approved`: contract drafts and invoices become possible |
| `pending` | reject (reason required) | IMC | `rejected`: no contract draft or invoice; the agreement and history stay |

A decision is final (409 on a second one). The full path a client shows is: request → provider answer → negotiation (messages and offer versions) → agreement (accepted offer) → IMC review → contract draft (never binding, OQ-17) → invoice (when billing rules are configured, OQ-15/OQ-16). Accepting an offer alone never creates an approved subscription, a contract, an invoice or a payment.

## 2d. Factory legal change request (ADR-020, PROPOSED, OQ-45)

`pending` → `approved` (values and documents applied, earlier documents superseded) | `rejected` (reason required, recorded values kept) | `cancelled` (by a member). One pending request per factory.
