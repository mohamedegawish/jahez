# ADR-025: Readiness-based service eligibility and factory transformation plans

- **Status:** Accepted (2026-10-05). **Supersedes in part [ADR-018](ADR-018-digital-readiness-assessment.md) §8** ("recommendations are not eligibility" still holds; the readiness *level* now gates availability).
- **Decided by:** Owner brief "Readiness-Based Service Eligibility, Ministry Roadmap Orchestration & Factory Roadmap UI" (2026-10-05). Four choices were made by the owner during planning (questions asked in the session), listed under Decision; the rest follows the brief.

## Context

Until now a factory could see and request every catalog service an eligible provider offered (approved provider, approved listing, one of the factory's sectors; ADR-014, ADR-021), whatever its readiness level, and even before its first assessment. The readiness roadmap of ADR-018 only *recommended* services per category. The brief asks that:

- the ministry (IMC) decides which services each of the four readiness levels makes available;
- the backend, not the web client, enforces it on every path;
- IMC builds a per-factory, multi-stage transformation plan with sequential and parallel services, linked to the existing marketplace, and the factory sees it.

The workflow already ends at an agreement, IMC's review of it and a non-binding contract draft (ADR-015, ADR-017, ADR-020). Nothing in the platform records the delivery or completion of a service.

## Decision

### 1. Level services (IMC-managed, nothing seeded)

- **Table** `readiness_level_services`: `level` (one of the four stable category codes `b4_automation`, `basic`, `advanced`, `smart`, CHECK), `catalog_service_id`, `is_active`, created-by and updated-by. Unique `(level, service)`. A service may belong to several levels. Removing a row or switching it off never touches the catalog service.
- **Keyed by category code,** so a row applies whichever questionnaire version classified the factory.
- **Nothing is seeded** (owner decision: "enforce now, hints only"). The administration shows each level's roadmap-recommended services (ADR-018 §5) as hints; IMC adds each one explicitly. Only the local `DemoDataSeeder` (ADR-024) maps demo services, through the API.
- **Endpoints:**
  - `GET /readiness-levels` (`readiness_services.manage` or `transformation_plans.view_any`): the four levels, their ranges from the current questionnaire, their services with the count of approved providers holding an approved listing, recommended hints, and a summary of unassigned services, multi-level services and active services with no provider.
  - `PUT /readiness-levels/{level}/services/{catalogService}` `{is_active}`: an idempotent upsert.
  - `DELETE` of the same path.
  - Every change is audited.

### 2. One eligibility source

> **Amended by [ADR-026](ADR-026-cumulative-levels-and-progression.md) (2026-10-06):** levels are cumulative (a service available to a level is available to every higher level), the factory's level also counts the levels it opened by completing its plan's services, and a factory member no longer receives any score.

`App\Readiness\ServiceEligibility` is the only code that combines the provider scopes (an architecture test enforces it).

- **A service is available to a factory when:**
  - the factory has a current readiness assessment (its latest; a self-assessment counts until expert validation is decided, OQ-08), **and**
  - an active `readiness_level_services` row exists for that assessment's level.
- **A provider is eligible for a factory and a service when, in addition:**
  - the provider is approved;
  - its listing of the service is approved;
  - it targets one of the factory's sectors.
- **Every factory-facing path uses it:**
  - the catalog and categories (a factory member sees only available services; others 404);
  - service listings, with `meta.eligibility_status`;
  - the provider directory (list, `filter[service]`, profile services, logo);
  - `GET /factories/{id}/service-eligibility[/{service}/providers]`;
  - service request creation and adding providers, re-checked inside the transaction with a share lock on the level row;
  - transformation plans.
- **Unchanged:**
  - IMC and providers keep the full catalog.
  - Promotions only reorder, and `filter[recommended]` only narrows.
  - A factory without an assessment gets no service.
- **Requests already open stay as they are** when a level changes; only new requests and added providers are checked (OQ-53).

### 3. Transformation plans

**Tables:**
- `transformation_plans`: one open plan per factory, through `is_open`. Status `draft | published | suspended | closed`. The readiness basis is the assessment at creation, then at each publication.
- `transformation_plan_versions`: at most one draft and one published version (true/NULL unique markers). Superseded versions are kept. A `revision` counter supports optimistic saves.
- `transformation_plan_items`: one row per plan and catalog service (unique, so a service appears once per plan and a retry reuses the row). It holds the execution status, which survives new versions.
- `transformation_plan_stages`: ordered by `position` per version.
- `transformation_plan_stage_items`: an item's placement in a version, holding its stage, order, assigned provider, instructions, internal notes and planned dates. Composite foreign keys keep everything in one version.
- `transformation_plan_dependencies`: finish-to-start dependencies between placements of one version. Self-dependency is refused by a CHECK; cycles are refused by the application.
- `service_requests.transformation_plan_item_id`.
- All plan foreign keys are `RESTRICT`: MySQL 8 forbids CHECK constraints on cascading columns, so the application deletes draft rows children first.

**Writing a plan (IMC, `transformation_plans.manage`).** `App\TransformationPlans\TransformationPlanDraft`:
- **Create:** needs an assessment and no other open plan.
- **Start a draft:** copies the published version.
- **Save:** replaces the whole structure under a lock on the plan row; a stale `based_on_revision` gets 409; unknown or duplicated services, dependencies outside the plan, self-dependencies and cycles get 422.
- **Discard, delete:** delete only for a plan never published.
- **Publish:** refused with 422 while `TransformationPlanReview` reports a blocking problem.
  - Blocking: no assessment; no stage; an empty stage; a service not available at the level (unless already underway); an ineligible assigned provider; a cycle; dropping an item that has started or has a live request.
  - Warnings: the level changed; no eligible provider; the factory is not approved.
  - From version 2 a change note is required.
- **Suspend, resume, close.**
- Every change is audited and notifies the factory's members.

**Reading a plan.** `TransformationPlanPresenter` renders a version for IMC, or for the factory without internal notes, change notes, actors or status reasons. For each item it gives:
- its recorded execution status and a computed **state**: `waiting_prerequisites`, `not_started`, `awaiting_approval`, `ready`, `in_progress`, `on_hold`, `completed`, `cancelled`;
- what it waits for (names only), its dependents, and the items of its stage it can run in parallel with (no chain of dependencies between them);
- the linked request's real progress (`RequestProgress`, read from the request, its threads, the agreement and IMC's review);
- `service_available`, and a `can_request` hint.

Progress is completed ÷ (total − cancelled), or null when no item counts. Factory members see only plans that have been published; providers and other factories get 404.

**Owner decisions on execution (2026-10-05):**
1. **IMC records execution** (start, complete, hold, resume, cancel, reopen) until the ministry defines who reports it and on what evidence ([OQ-52](../open-questions.md#oq-52)). Factories and providers read it only.
   - `start` requires all of: the plan is published; every prerequisite item in the published version is completed; the linked request's agreement is approved by IMC (or `JAHEZ_AGREEMENTS_IMC_APPROVAL_REQUIRED` is off).
   - A completed item is final. A cancelled prerequisite keeps its dependents waiting until IMC publishes a version without it.
2. **A request may be sent before the prerequisites are completed**: sending a request never starts an item; only the start is gated.
3. **An assigned provider is binding**: the request for that item, and any provider added to it, must be the assigned provider. Changing the assignment needs a new version ([OQ-54](../open-questions.md#oq-54)).
4. **Rollout**: enforcement starts at once with nothing seeded (see 1).

**Link to the marketplace.**
- `POST /service-requests` accepts `transformation_plan_item_id`. The item must be in the factory's published version, for the same service; the plan must be published (409 when suspended or closed); the item must not be completed or cancelled.
- Only one live request per item (409). A cancelled request, or one whose agreement IMC rejected (final per OQ-43), frees the item.
- One request still covers one service (ADR-015); nothing else in the request, negotiation, agreement, review or contract workflow changes.

**Lock order for a linked request:**
1. factory (share);
2. plan (share);
3. item (for update);
4. level row (share);
5. providers (share).

IMC actions lock the plan, then the item.

### 4. Permissions and audit

- **Permissions:** `readiness_services.manage`, `transformation_plans.view_any` and `transformation_plans.manage` belong to `imc_admin`.
- **Audit events:** `readiness_level_service.assigned|updated|removed` (subject: the catalog service) and `transformation_plan.created|deleted|draft_started|draft_saved|draft_discarded|published|status_changed|item_status_changed`. Metadata holds ids, codes, counts and from/to; no instruction or note text, except the action reason, as for other IMC decisions.
- **Notifications** to factory members: plan published, plan status changed, item status changed.

## Consequences

- **Existing factories:** a factory with no assessment, or whose level has no active service, sees no service until IMC configures levels. The web client explains both states.
- **Tests:** tests that created requests or browsed services as a factory now give the factory an assessment and map the services (`availableTo()`, `everyServiceAvailable()`), without weakening their assertions. Lock-order tests include the level row. The Postman collection gains folder `04a` (Factory A assessed, demo services available to Basic) before the catalog folder, and folder `21`. Folder 05 reads the workbook's shape as IMC.
- **Level changes:** a new assessment never rewrites a published plan. Items whose service is no longer available show `service_available=false` and cannot be requested, and IMC sees a `readiness_changed` warning (OQ-53).
- **Still not built:**
  - delivery evidence, provider-reported completion, and correction of a completed item (OQ-52);
  - a binding contract (OQ-17);
  - expert validation of the self-assessment (OQ-08).
