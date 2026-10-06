# Phase log: listing packages and prices, and the factory cart (owner request, 2026-10-06)

**Request (owner, Arabic, 2026-10-06):**
- providers write monthly and annual prices for their services, and the number of users (for example for ERP);
- a field that does not apply can be left out;
- a factory requesting several services gets a shopping cart.

**Decision record:** [ADR-027](../decisions/ADR-027-listing-packages-and-cart.md).

**Nothing was committed, pushed or deployed.**

## 1. Owner decisions taken in this session (asked before building)

| Question | Answer |
| --- | --- |
| Shape of the price | Several packages per service, each with monthly and annual prices and a number of users |
| "Cancel it if it does not apply" | Every field is optional (at least one price per package) |
| Does a price change need IMC review again? | Yes: the listing goes back to `pending` and is hidden from factories until approved |
| What goes in the cart | A provider's offer of a service with the package, period and users; checkout sends one request per service |

## 2. API changes

- **New tables:**
  - `service_listing_packages`;
  - `service_cart_items`;
  - `provider_requests.selection` (JSON copy of the choice).
- **Migrations:** `2026_10_08_100001` to `_100003`.
- **Endpoints:**
  - `PUT /service-providers/{id}/services/{catalogService}/packages`;
  - `GET|DELETE /cart`;
  - `POST /cart/items`;
  - `PATCH|DELETE /cart/items/{id}`;
  - `POST /cart/checkout`.
- **`packages` added to:** `GET /service-listings` and to the provider's `service_listings`.
- **`selection` added to** threads, for the two parties only.
- **Request creation:** `ServiceRequestController::openRequest()` now holds the creation shared by `store` and `checkout`, with the same lock order. Checkout adds the cart rows (for update) after the factory.
- **Audit:** `service_listing.packages_updated` (counts and statuses, never prices); `service_request.created` adds `source: cart`.
- **Notification:** `service_listing_packages_changed`, sent to IMC reviewers.

## 3. Web client

- **Provider «خدماتي»:** each card shows its packages, and «الباقات والأسعار» opens the package editor. A warning says that saving sends the listing back to review; the button is disabled while the listing is suspended.
- **Factory «الخدمات»:**
  - each card shows the packages, «طلب مباشر» and «أضف للسلة» (choose package, period and users);
  - a «سلة الطلبات (n)» button.
- **New page `/factory/cart`, with a sidebar link:**
  - items grouped by service, with package, period and users editable in place;
  - unavailable items are flagged;
  - the title and need are given per service;
  - estimated totals are labelled as estimates.
- **Thread views:** the factory's request detail and the provider's request show the cart choice.
- **IMC listing review:** shows the packages and the «حدّث المزود الباقات والأسعار» history entries.

## 4. Commands and results

| Command | Result |
| --- | --- |
| `composer analyse` (Larastan 8) | No errors (after three type fixes) |
| `vendor/bin/pint --dirty --format agent` | passed |
| `DB_PORT=3307 php artisan test --compact` on the touched files (requests, providers, administration, portal, plan execution, policies, architecture, versioning) | 427 passed |
| New `ServiceListingPackageTest` / `ServiceCartTest` (with lock order) / policy and audit cases | 14 / 17 + 1 / 125 passed together |
| Full suite `DB_PORT=3307 php artisan test --compact` | **1427 passed (5977 assertions)**, 455 s |
| Front `npm run build` / `npm run lint` / `npm test` | built / clean / 30 passed |
| Postman: new scratch DB `jahez_postman_adr027` on 3307 (`migrate` + `db:seed`), `php -S` with `MAIL_MAILER=log`, `node postman/run-collection.mjs … 400` | **997 assertions passed, 0 failed** (folder 22 added: 11 requests) |

**Incident.** While checking the migrations, `DB_PORT=3307 php artisan migrate:fresh --env=testing` was run. There is no `.env.testing`, so it targeted `DB_DATABASE=jahez` from `.env` and wiped the `jahez` database on the MySQL 8.4 server (3307). The development database on MariaDB (3306) was not touched (10 users, checked). Binary logging is on for 3307 (30-day retention), so the earlier contents may be recoverable by a binlog replay; nothing was attempted without the owner.

## 5. Not done or still open

- **OQ-57:** whether listed prices bind, include tax, or should ever lead to payment; whether IMC sees selections.
- A direct request (`POST /service-requests`) still carries no package choice; only the cart records one.
- Listed prices are never copied into offers, agreements or invoices.
