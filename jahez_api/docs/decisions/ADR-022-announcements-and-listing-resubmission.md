# ADR-022: Landing-page announcements, listing resubmission, and the catalog's ownership

- **Status:** Accepted (2026-10-04).
- **Decided by:** Owner brief "Phase 2 & 3 Gap Closure and Production-Readiness Verification" (2026-10-04): the landing-page advertisements were browser-local demo data; rejected services could not be resubmitted; catalog activation was to be reviewed against ADR-014. The technical choices below were made during implementation.

## 1. Landing-page announcements (`public_announcements`)
- **Why not service promotions (ADR-020):** a promotion belongs to one provider's listing of one catalog service and is shown only to factories eligible for that provider. The landing page shows platform announcements (workshops, campaigns, calls to register) to anonymous visitors: no provider, no eligibility. Reusing promotions would either leak listings publicly or bend their eligibility rule, so announcements are their own record. There is no other advertising record; the browser-stored demo ads were removed.
- **Fields:** title, description, badge text, colour preset (the six card palettes), an optional link, up to five tags, an optional duration text, an optional cover image with its alternative text, a display order, an optional start and end, and the publication time. A draft has no publication time.
- **Visibility:** visitors see an announcement while it is published, started and not ended (`PublicAnnouncement::live()`), ordered by `sort_order` then newest. `GET /public/announcements`, `/public/announcements/{id}` and `/{id}/cover` need no token and answer 404 for anything not live.
- **Links stay inside the platform:** the API accepts only a path of the web client (`/register/factory`), never a scheme, host or protocol-relative URL, so the public page cannot send visitors to an address an administrator typed.
- **Cover images** are stored on the private documents disk (jpg, png, webp; the logo size limit) and streamed by the API; a draft's cover is readable only by administrators.
- **Administration:** permission `announcements.manage` (IMC). Drafts are created, edited, published and unpublished; only an unpublished announcement can be deleted. Each change is audited (`announcement.*`) with the title or the changed field names only.
- **Not decided here (OQ-44):** fees, approval steps beyond IMC's own decision, limits. None is implemented.

## 2. Resubmitting a rejected listing
- `POST /service-providers/{id}/services/{catalogService}/resubmit` (the provider's own members, optional `note`): the same listing row moves `rejected → pending`; the rejection reason is cleared from the row and stays in the audit history (`service_listing.resubmitted`, with the note). It is never approved automatically.
- Only `rejected` can be resubmitted: `pending` (a repeated click) and `approved` answer 409; a `suspended` listing is IMC's to reinstate. IMC reviewers are notified (`service_listing_resubmitted`), and the provider's members get the confirmation.
- The correction itself is made in the provider's profile (description, documents): a listing has no fields of its own.

## 3. Catalog activation: not added
ADR-010 rule 4 ("only seeders write reference data") and ADR-014 make the catalog seeder-owned: the services workbook is the source, `ServiceCatalogSourceTest` compares every row with it, and the seeder is safe to rerun. A runtime active flag written by administrators would be reference data written outside the seeder, so it is **not** added. A catalog service is added, renamed or removed only by updating the workbook and the seeder. To take one provider's service off the factory portal, IMC suspends that listing (ADR-021); a suspended listing is not eligible for requests, listings, the directory or `filter[eligible]` (tested). If the owner wants ministry-level activation, it needs a decision that amends ADR-010/ADR-014.
