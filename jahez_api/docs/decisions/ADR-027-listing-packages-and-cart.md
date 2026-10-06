# ADR-027: Listing packages and prices, and the factory cart

- **Status:** Accepted (2026-10-06).
- **Amends:**
  - [ADR-021](ADR-021-ministry-administration.md) and [ADR-022](ADR-022-announcements-and-listing-resubmission.md): a third way a listing returns to review;
  - [ADR-015](ADR-015-marketplace-requests.md): requests may be sent several at once.
- **Decided by:** Owner request (2026-10-06):
  - providers write a monthly and an annual price for their services, and the number of users (for example for ERP);
  - a field that does not apply to a service can be left out;
  - a factory requesting several services gets a shopping cart.

  Four choices were made by the owner in the same session (questions asked before building). They are listed under Decision.

## Context

**Before this decision:**
- **No price on a listing:** a listing (`catalog_service_service_provider`) carried no price. Providers priced each request through offers (ADR-015), and the provider portal said so.
- **One request at a time:** a factory sent one request at a time, one service to one or several providers.

**Constraints that still hold:**
- prices are EGP decimal strings and informational;
- no payment, invoice, contract or fee may follow from a marketplace step until OQ-15, OQ-16 and OQ-17 are answered;
- IMC approves each listing before factories see it (ADR-021).

## Decision

### 1. Packages (owner decision: several packages per service)

**Table** `service_listing_packages`, keyed to the listing (composite FK to `catalog_service_service_provider`, cascade).

**Fields of each package:**
- position;
- `name_ar`;
- `monthly_price` (nullable);
- `annual_price` (nullable, DECIMAL(14,2));
- `users_count` (nullable, ≥ 1).

**Rules:**
- **Optional fields** (owner decision): any field that does not apply may be left out, but each package states at least one price (CHECK and 422).
- **Size:** at most 10 packages per listing.
- **No packages:** a listing may have none, and factories then read «the provider prices it in its offer».

**Endpoint** `PUT /service-providers/{id}/services/{catalogService}/packages` `{packages: [...]}`:
- **Who:** the provider's own members only (`ServiceProviderPolicy::updateListingPackages`). IMC gets 403; anyone else 404.
- **Replaces the whole list:** sending the same list again changes nothing.

**Review again** (owner decision). A change moves the listing (row locked):
- `approved` → `pending`;
- `rejected` → `pending`;
- `pending` stays `pending`;
- `suspended` → **409**, because a suspension is IMC's to lift.

Factories do not see a pending listing, so new prices reach them only after IMC approves.

**Audit and notifications:**
- audit `service_listing.packages_updated`: service, package count from/to, and status from/to. Never a price.
- IMC reviewers are notified (`service_listing_packages_changed`).

**Shown:**
- on `GET /service-listings` (`packages`, every viewer);
- on the provider resource (`service_listings[].packages`);
- in IMC's listing review.

### 2. The cart (owner decision: a provider's offer with its choice)

**Table** `service_cart_items`. Each item holds:
- `user_id`, `catalog_service_id`, `service_provider_id`;
- `service_listing_package_id` (set to null when the provider replaces its packages);
- `billing_period` (`monthly` | `annual`);
- `users_count`.

Unique per member and listing. An item goes when its listing goes.

**Ownership:**
- the cart belongs to a factory member (`ServiceCartItemPolicy`; another account's item is 404);
- only factory members have one, because only they send requests.

**Endpoints:**
- `GET /cart`: items with their availability (re-checked through `ServiceEligibility`, with the reason when unavailable) and the listing's packages, plus a summary;
- `POST /cart/items`: adds, or replaces the choice of a listing already there (201 or 200);
- `PATCH /cart/items/{id}`;
- `DELETE /cart/items/{id}`;
- `DELETE /cart`.

**Validation:**
- the provider must be eligible for the factory and the service;
- the package must be the listing's;
- a period needs a package that has a price for it.

**Totals.** The summary's `estimated_monthly_total` and `estimated_annual_total` add the listed prices of the available items. They are estimates, labelled as such; nothing is quoted, invoiced or paid.

### 3. Checkout

**Request.** `POST /cart/checkout` `{requests: [{service, title, need, requirements?}]}`.

**Result:**
- one service request per listed service, to every provider in the cart for it, with the existing request rules (approved factory, ≤ 20 providers, eligibility re-checked in the transaction);
- each thread stores `provider_requests.selection`, a **copy** of the package as listed then, the period and the users, so later price changes never rewrite a sent request;
- the items sent leave the cart, and services not listed stay;
- all or nothing.

**Lock order:**
1. factory (share);
2. the member's cart items (for update: a repeated checkout finds them gone, 422);
3. then, per service in catalog id order, the readiness level row (share) and the providers (share).

**Audit and notifications:** `service_request.created` adds `source: cart`, and providers are notified as for any request.

**Visibility.** `selection` is shown to the two parties of the thread only, never to IMC (as for negotiation, OQ-39).

### 4. Unchanged

- Providers still answer with offers, and only an accepted offer becomes an agreement (ADR-017).
- The cart never pays, reserves or invoices.
- A request sent directly (`POST /service-requests`) carries no selection.

## Consequences

- **Rule extension:** listing statuses now change through IMC review, the provider's resubmission (ADR-022) and the provider's package change (this ADR). The agent guidelines say so.
- **Interim (OQ-57, PROPOSED):**
  - listed prices are not binding and are not copied into offers;
  - whether they may include tax, and whether IMC should see the factory's selection, are open.
