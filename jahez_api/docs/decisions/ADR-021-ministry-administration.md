# ADR-021: Ministry (IMC) administration, approvals and assessment analytics (Phase 3)

- **Status:** Accepted (2026-10-04). Rules marked PROPOSED wait for owner confirmation through the linked open questions.
- **Decided by:** Owner brief "Phase 3 — Complete Ministry/Admin Portal, Approvals, Notifications & End-to-End Verification" (2026-10-04): separate provider and factory approval pages with details and decisions (approve, reject, request corrections, suspend); review of provider-submitted service listings; promotions that never bypass factory eligibility, provider approval or service approval; assessment administration and analytics from real data; notifications for the new events; real mail verification. The technical choices below were made during implementation.

## Decisions

### 1. Corrections requested (`changes_requested`)
- A new approval state for providers (`ProviderApprovalStatus`) and factories: `pending → changes_requested` with a required note; from there IMC approves or rejects, and the organization's own member returns it to `pending` with `POST …/review-request` (as after a rejection). Like `rejected`, it keeps a provider hidden from factories.
- Every decision but approval needs a reason. Approval still requires the configured required fields (OQ-36 providers, OQ-19 factories).

### 2. Factory approval (OWNER brief; consequences PROPOSED, OQ-46)
- `factories.approval_status | approval_reason | approval_changed_at`, the same states and transitions as providers (`FactoryApprovalStatus`). `POST /factories/{id}/approval` (permission `factories.approve`), `POST /factories/{id}/review-request` (the factory's members). Row-locked, audited (`factory.approval_changed`, `factory.review_requested`), notified.
- New rows default to `pending`; self-registered factories stay pending; factories IMC creates are approved at creation; factories that existed before this step were backfilled as approved by the migration, so nothing that worked stops working.
- **Separate from the readiness classification:** no approval decision reads or writes an assessment, its score or its category (tested). There is no manual category override.
- **What approval gates (PROPOSED, OQ-46):** with `JAHEZ_FACTORY_APPROVAL_REQUIRED` (default on) only an approved factory sends service requests or adds providers to one (409 otherwise; the factory row is share-locked in the same transaction). Profile, documents and the readiness assessment stay open throughout; listings and the directory stay visible.

### 3. Service listing review (OWNER brief)
- Each provider–service row of `catalog_service_service_provider` carries `status` (`pending`, `approved`, `rejected`, `suspended`), `status_reason`, `status_changed_at`, `submitted_at`. New rows default to `pending`; rows that existed were backfilled as approved.
- `POST /service-providers/{id}/services/{catalogService}/review` (permission `service_listings.review`): row-locked, `ServiceListingStatus::canBecome`, audited as `service_listing.reviewed` on the provider (service code, from, to, reason), notified.
- A listing reaches factories only when **both** the listing and the provider are approved and the provider targets one of the factory's sectors: `ServiceProvider::offering()` (request eligibility), the listing feed, the directory (`approvedServices`) and the catalog's `filter[eligible]` all use approved listings. Promotions only reorder what is already visible.
- A provider editing its services keeps the status of the listings it keeps; newly added ones start pending and IMC reviewers are notified. A rejected listing is sent back to review with `POST …/resubmit` (ADR-022); removing and adding the service again also creates a new pending listing.
- The ministry catalog itself (7 categories, 42 services) stays seeder-only (ADR-010, ADR-014): it is not edited, activated or deactivated from the API. Listing-level suspension is how IMC takes one provider's service off the factory portal.

### 4. Readiness administration and analytics
- `GET /readiness-assessments` (IMC, `assessments.view_any`): every submitted assessment with its factory; `filter[current]` keeps each factory's latest (its classification), plus category, version, period and name search.
- `GET /readiness-analytics`: registered factories, factories with at least one completed assessment, completion rate (assessed ÷ registered; assessments are submitted whole, so "incomplete" means "none submitted"), current classification per category with average scores, submissions per month and per version in a period, and the current questionnaire's structure with `definitionProblems()`. Everything is read from stored assessments; nothing is recomputed or changed.
- The categories and thresholds stay those of the source document (10–17, 18–25, 26–33, 34–40); versioning and the threshold-tiling validation of ADR-018 are unchanged.

### 5. Notifications added (same infrastructure as ADR-020)
`organization_registered` and `review_requested` (to IMC reviewers), `factory_approval_changed`, `service_listing_submitted` (IMC), `service_listing_reviewed`, `promotion_started` / `promotion_ended` (provider), `readiness_assessment_completed` (factory; no score in the text). Links point to the new admin pages.

### 6. Review history
The provider and factory details pages show the decisions from the append-only audit log filtered by subject, so there is one history mechanism, not a second table.

### 7. Mail
`php artisan app:mail-check <address> [--dry-run]` reports the transport and the missing `MAIL_*` settings by name only, sends one platform email synchronously and reports the transport's answer. A failing transport never rolls back a business change and never loses the in-app notification (tested).

## Consequences
- Tests that need an approved factory get one from `FactoryFactory` by default (`withApprovalStatus()` for others); `ServiceProviderFactory::offering()` attaches approved listings (`listing()` for other statuses).
- The two lock-order tests of `ServiceRequestTest` now include `factories:share` before the provider share-locks.
- The web client gains separate approval pages and details for providers and factories, a listing review queue, a sensitive-changes page, readiness analytics and results, and an IMC requests page that shows statuses only (OQ-39).

## Amendment (2026-10-04): the factory list is a card summary

Owner brief "Dynamic Assessment Administration, Card-Based Listings & Realistic Demo Data" asked for factory and service cards that do not carry sensitive details.

- `GET /factories` now returns `FactorySummaryResource`: name, size, place, sectors, the logo document id, onboarding, the current readiness result, the approval, `profile_completion` (which profile fields are filled, by name, never their values) and `service_requests_count`. The legal name, contact person and details, address, registration numbers and non-logo documents are only on `GET /factories/{id}` and the review page. Filters, sorting, pagination and authorization are unchanged.
- The service listing resource already carried no legal or contact detail of the provider; a test now asserts it for factories and IMC.
- The web client shows both lists as cards in the advertisement-card style, with the same actions as before (details, review page with documents, change requests and history, approval decisions by permission, assessment results; listing details with the approval history, decisions, provider profile, promotion). A promotion is offered only on an approved listing of an approved provider, and in any case only reorders what eligible factories already see.
