# ADR-015: Marketplace requests, provider responses and negotiation

- **Status:** Accepted (2026-10-03, Phase 6). The operating model is **owner-approved**; the states and their transitions are **PROPOSED** until confirmed ([OQ-38](../open-questions.md#oq-38)).
- **Decided by:** the project owner confirmed the model in the combined Phase 4–7 brief:

  > Jahez is a factory-initiated B2B service marketplace. A factory sends a request to one or several eligible providers; each provider independently accepts or rejects; if a provider accepts, the factory and that provider negotiate privately inside Jahez.

  That answers [OQ-03](../open-questions.md#oq-03). EGP-only offer prices are an owner decision of 2026-10-03.

## Model: one parent request, one child per provider

| Table | Holds |
| --- | --- |
| `service_requests` | The factory's request: service, title, need, requirements, status (`open`, `awarded`, `cancelled`), creator. The shared content is stored once. |
| `provider_requests` | The request as sent to **one** provider: that provider's status, an optional reason, and the accepted offer. Unique per request and provider. |
| `provider_request_messages` | Append-only messages of one thread. They are conversation, not offers. |
| `offers` | Append-only offer versions of one thread: scope, deliverables, duration, price (DECIMAL(14,2)) and currency (`EGP`). |

**Why a parent with children rather than separate linked requests:**
- The factory compares responses on one record.
- The request text is not copied per provider.
- Each provider's status, messages and offers live on its own child row, which carries the privacy boundary.

## Privacy

- **Providers:** a provider sees the shared request content and **only its own thread**. Its resource never lists the other recipients, and its messages and offers are never visible to competitors (404).
- **The factory** sees every thread of its own requests.
- **IMC administrators:** they may see requests and thread statuses, **never message bodies or offer terms** (PROPOSED, [OQ-39](../open-questions.md#oq-39)).
- **The audit log** records statuses and offer version numbers, never prices, terms or message text.

## Workflow and concurrency

- **States** are listed in [docs/workflows.md](../workflows.md).
- **Clients never set a status.** Each change is an explicit endpoint (accept, decline, withdraw, cancel, accept offer). The server checks the actor (policies) and the current status (`ProviderRequestStatus::canBecome`), and refuses anything else with 409.
- **Locking:** every status change locks the parent request row, then the thread row (always in that order), inside one transaction. Concurrent actions therefore queue rather than deadlock, and each one sees the previous result. Messages and offer versions change nothing on the request, so they lock only their thread. Creating a request share-locks the chosen providers while it re-checks their eligibility. The lock sequences are verified by test.
- **Suspension pauses a thread** (PROPOSED, [OQ-40](../open-questions.md#oq-40)): while the provider is not approved, every step that moves a thread forward gets 409. Leaving it (decline, withdraw, cancel) still works.
- **Offers carry `based_on_version`** (the latest version the provider saw). A stale or repeated submission gets 409, which makes retries safe without an idempotency key.
- **Only the latest offer version can be accepted.** Accepting it makes that thread `agreed`, the request `awarded`, and closes the other open threads. All of it is in one transaction with its audit entry.

## Contract boundary

`agreed` is **not** a contract, an invoice or a payment. No such records exist ([OQ-15](../open-questions.md#oq-15), [OQ-16](../open-questions.md#oq-16), [OQ-17](../open-questions.md#oq-17)), and a test checks that accepting an offer creates none.

## Technical limits (not business rules)

- At most 20 providers per request (PROPOSED bound).
- Text fields: messages and offer texts at most 5000 characters; titles at most 200.
- Duration from 1 to 3650 days.
- Messages: 30 per minute per user. Offers: 10 per minute per user.
