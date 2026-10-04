# ADR-020: Provider and factory portals (Phase 2)

- **Status:** Accepted (2026-10-04). Business rules marked PROPOSED wait for owner confirmation through the linked open questions.
- **Decided by:** Owner brief "Phase 2 — Complete Provider and Factory Portals" (2026-10-04): ministry (IMC) approval before any approved subscription or contract; request-specific negotiation with unread indicators; in-app and email notifications; IMC promotions («إعلان») that never bypass eligibility; provider and factory reports from stored data; reviewed changes to sensitive factory information. The technical choices below were made during implementation.

## Decisions

### 1. IMC review of agreements (OWNER brief; aftermath PROPOSED, OQ-43)
- `agreement_reviews`: one append-only decision per agreement (`approved` or `rejected` with a required reason), unique per agreement, by holders of the new permission `agreements.review`. No row means `pending`.
- `POST /agreements/{id}/review` locks the agreement row; a second decision gets 409. Audited as `agreement.reviewed` (decision and reason, never the price).
- A contract draft (`POST /agreements/{id}/contracts`) and an invoice draft (`POST /agreements/{id}/invoices`) now need an approved agreement, or answer 409. The rule is the setting `JAHEZ_AGREEMENTS_IMC_APPROVAL_REQUIRED` (default on).
- Reviewers see the agreed terms and price, which they decide on. They still never see negotiation messages or contract training plans. This narrows the OQ-39 interim for agreements only.
- Approval makes nothing binding: contracts stay `draft_not_binding` (OQ-17). What a rejection means for the request and its parties is not decided (OQ-43); the agreement and its history stay.

### 2. Notifications
- Laravel's `notifications` table (database channel) stores in-app notifications; `GET /notifications` (with `meta.unread_count`), `POST /notifications/{id}/read`, `POST /notifications/read-all`. Only the account's own notifications are reachable (others 404).
- `App\Notifications\PlatformNotifier` sends after the business transaction commits (`DB::afterCommit`), swallows and reports notification errors so they never roll back a valid change, and derives each notification id from the event key and recipient (SHA-256), so a retried event stores and emails at most once.
- Email: `PlatformEventMail`, queued (`afterCommit`, 3 tries) on the existing database queue, through the configured `MAIL_*` transport, only for accounts with `users.email_notifications` on (default on; `PATCH /me`). Emails say what happened and link to the record; they never carry message text, offer terms, prices or documents. `JAHEZ_NOTIFICATION_EMAILS=false` turns emails off platform-wide.
- Events: request received, accepted, declined, withdrawn, closed (cancelled / awarded elsewhere), message received, offer submitted, offer accepted, agreement awaiting review (IMC), agreement approved / rejected, contract draft made / cancelled, invoice issued, payment confirmed (verified gateway evidence), provider approval changed, legal change request submitted (IMC) and decided.
- Not built: due-date reminders and overdue transitions (no due dates exist, OQ-16), service-level approval events (no per-service approval exists), contract activation / expiry (not modelled, OQ-17).

### 3. Read marks
- `provider_request_reads` (one row per user and thread, last read message id, only moves forward). `POST /provider-requests/{id}/read` marks the thread read for the calling party (IMC 403, others 404); posting a message marks the author's own read point. Reading messages does not mark them, so background polling never hides new ones.
- Thread resources carry `unread_messages_count` (parties only), `last_message_at`, `latest_offer_version` and the agreement's `review_status` / `contract_status`. `GET /provider-requests` adds `search`, `sort` (`newest`, `oldest`, `recent_activity`), `filter[unread]` and `filter[service]`; `GET /service-requests` adds `search`, `sort`, `filter[service]` and `filter[thread_status]`.
- There is no push channel: the web client polls (30 s in a thread, 60 s for the bell).

### 4. Service listings and promotions (PROPOSED placement rules)
- `GET /service-listings`: one row per provider and catalog service it offers. A factory member sees only eligible listings (`ServiceProvider::eligibleFor()`); a provider its own; IMC all. `filter[recommended]` narrows a factory's listings to its readiness roadmap and needs an assessment.
- `service_promotions` (IMC, permission `promotions.manage`): provider + service the provider offers, optional headline, priority 0–1000, start and optional end; ended, never deleted. Audited (`promotion.created|updated|ended`). `GET/POST /promotions`, `PATCH /promotions/{id}`, `POST /promotions/{id}/end`.
- Running promotions sort first (by priority) and carry the label «إعلان». A listing appears once whether or not promoted. A promotion never adds a listing: an unapproved, suspended or other-sector provider's promoted listing stays invisible to the factory.

### 5. Reports
- `GET /reports/marketplace?from=&to=&filter[service]=` (UTC days, at most two years; default the last twelve months), scoped to the caller's organization (IMC: all). Counts by status and month, top services, provider response times (from the thread history), conversion counts with rates only when the base is not zero, agreements by review status, invoice counts by status and totals of issued invoices per currency. No commission, outstanding balance or contract expiry is computed (OQ-15, OQ-16, OQ-17). The web client exports the response as CSV.

### 6. Factory legal changes (PROPOSED, OQ-18)
- Factories have no approval step, so a legal value (legal name, registration numbers, registration documents) counts as recorded once it is set. Members fill an empty value directly; a recorded one changes only through a change request IMC approves (`factory_profile_change_requests`, mirroring ADR-019; `organization_documents.factory_profile_change_request_id`). Setting: `JAHEZ_FACTORY_LEGAL_CHANGES_REVIEWED` (default on).
- Endpoints: `GET /factory-change-requests` (IMC queue), `GET/POST /factories/{id}/change-requests`, `POST .../{changeRequest}/approve|reject|cancel`. Audited `factory.change_request_*`. Decisions notify the factory.

### 7. Invoices
- `InvoiceResource` adds `parties` (factory, provider, service), `type` (`agreement_service`, the only type) and `due_date` (always null: no payment terms, OQ-16). `GET /invoices` adds `filter[service]`, `filter[from]`, `filter[to]` (creation day) and `filter[counterparty]`.

### 8. Account settings
- `PATCH /me`: the display name and `email_notifications`. The email, role, organization and password are not changed here.

## Consequences
- Contract and invoice tests now approve the agreement first (`agreedMarketplace()` approves by default; `imcApproved: false` leaves it pending).
- New production settings have safe defaults; none holds a secret.
- The registration controller now passes a registration id to the `RegisterOrganization` job (the job signature had changed without its caller) and deletes staged uploads when staging or queuing fails.
