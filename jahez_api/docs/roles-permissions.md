# Roles and Permissions

Source of truth in code: `App\Enums\Role`, `App\Enums\Permission`, `app/Policies/*`. Decisions: [ADR-006](decisions/ADR-006-roles-and-permissions.md) and [ADR-011](decisions/ADR-011-account-provisioning-and-credentials.md). The matrix below is tested in `tests/Feature/Policies/`.

## Roles

| Role (`users.role`) | Who | Organization link | Platform permissions |
| --- | --- | --- | --- |
| `imc_admin` | IMC staff administering the platform (مركز تحديث الصناعة) | none | all (below) |
| `factory_member` | Staff of one factory (مصنع) | `factory_id`, required | none |
| `provider_member` | Staff of one service provider (مقدم الخدمة) | `service_provider_id`, required | none |

A database CHECK constraint enforces the organization link column. The DOC §7 IMC departments (Digital Transformation, Competitiveness & Productivity, Executive Management, certified assessment experts) are not yet separate roles ([OQ-21](open-questions.md#oq-21)).

## Permissions

| Permission | Grants |
| --- | --- |
| `factories.view_any` | List factories and view any factory |
| `factories.create` | Create factories |
| `factories.update` | Update any factory |
| `service_providers.view_any` / `.create` / `.update` | The same for service providers |
| `users.view_any` | List accounts and view any account |
| `users.create` | Create (invite) accounts |
| `users.update` | Rename, deactivate or reactivate any account |
| `audit_logs.view` | Read the audit log (`GET /audit-logs`). Nobody can write, change or delete entries through the API. |
| `service_providers.approve` | Approve, reject or suspend a provider (P4, ADR-014); read the change-request queue and approve or reject legal change requests (ADR-019) |
| `service_providers.evaluate` | Record and read DOC §6 provider evaluations (OQ-13 interim) |
| `service_listings.review` | Approve, reject or suspend one service a provider lists (ADR-021) |
| `announcements.manage` | Create, edit, publish, unpublish and delete draft landing-page announcements (ADR-022). Visitors read live ones without a token |
| `factories.approve` | Approve, reject, ask for corrections or suspend a factory account (ADR-021); never changes its readiness classification |
| `assessments.view_any` | Read any factory's readiness assessments and legacy manual classifications (ADR-018). `assessments.create` was removed with manual classification on 2026-10-03 |
| `readiness_questionnaires.manage` | List questionnaire versions; create, edit, publish and delete drafts (ADR-018 addendum) |
| `invoices.view_any` | See every invoice and its payments, amounts included (PROPOSED billing oversight, ADR-017) |
| `invoices.manage` | Draft, edit, issue and cancel invoices **when the approved invoicing policy makes IMC the issuer** (ADR-023, OQ-16) |
| `financial_policies.view` | Read the financial and contract policies, their versions and history, resolve which policy applies and preview calculations (ADR-023) |
| `financial_policies.manage` | **Granted per person, no role holds it.** Create policies, draft, edit and submit versions, discard drafts |
| `financial_policies.approve` | **Granted per person, no role holds it.** Approve or reject submitted versions (never one the same person drafted, edited or submitted), withdraw a scheduled version, end an active one |
| `payments.record` | **Granted per person, no role holds it.** Record an authorised manual payment entry (with `invoices.view_any`) |
| `agreements.view_any` | See every agreement and contract draft: existence and status only, never terms, prices or the training plan (PROPOSED oversight, OQ-39) |
| `service_requests.view_any` | See every service request and provider-request status (P6, PROPOSED oversight, OQ-39). Never grants messages or offers. |

IMC administrators hold every permission **except** the three granted per person (ADR-023): `php artisan jahez:permissions grant <email> <permission> --reason="..."` (also `revoke`, `list`), audited as `user.permission_granted` / `user.permission_revoked`, valid only for an active IMC administrator. Who should hold them is [OQ-49](open-questions.md#oq-49). Factory and provider members hold none: everything they can do comes from ownership of their own organization and from their side of a request.

## Decision matrix

✅ allowed · 403 seen but forbidden · 404 reported as not found (existence not revealed)

### Factories

| Actor | list / create | view | update |
| --- | --- | --- | --- |
| IMC admin | ✅ | ✅ any | ✅ any |
| Member of this factory | 403 | ✅ | ✅ name and sectors (since P5) |
| Member of another factory | 403 | 404 | 404 |
| Provider member | 403 | 404 | 404 |

### Service providers

| Actor | list / create | view | update profile and services | approve / reject / suspend | ask for a new review (after a rejection) | evaluations |
| --- | --- | --- | --- | --- | --- | --- |
| IMC admin | ✅ | ✅ any | ✅ any | ✅ | 403 | ✅ |
| Member of this provider | 403 | ✅ | ✅ (never the approval) | 403 | ✅ | 403 |
| Member of another provider | 403 | 404 | 404 | 404 | 404 | 404 |
| Factory member | 403 | 404 | 404 | 404 | 404 | 404 |

### Catalog and provider directory

| Actor | catalog (`/catalog/*`), reference data (`/reference/*`) and `/readiness-questionnaire` | `filter[eligible]` on services | `filter[recommended]` (services, directory) | provider directory and profiles |
| --- | --- | --- | --- | --- |
| IMC admin | ✅ | 422 | 422 | ✅ every approved provider |
| Factory member | ✅ | ✅ | ✅ once the factory has a readiness assessment (422 before) | ✅ approved providers targeting the factory's sectors (others 404) |
| Provider member | ✅ | 422 | 422 | 403 |

### Readiness assessments (ADR-018)

| Actor | list, view | submit | legacy manual classifications (read) |
| --- | --- | --- | --- |
| IMC admin | ✅ any factory | 403 | ✅ any factory |
| Member of this factory | ✅ | ✅ (self-assessment) | ✅ |
| Member of another factory / provider member | 404 | 404 (before validation) | 404 |

Nobody records a manual classification any more (`POST /factories/{id}/assessments` answers 405).

### Service requests and negotiation (P6)

| Actor | create request | view request, thread history | cancel, add providers | answer (accept/decline) | withdraw | messages, offers (read) | post message | submit offer | accept offer |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Requesting factory member | ✅ (own factory only) | ✅ all threads | ✅ | 403 | ✅ | ✅ | ✅ | 403 | ✅ latest version only |
| Provider the request was sent to | 403 | ✅ **own thread only** | 403 | ✅ | 403 | ✅ own thread | ✅ | ✅ | 403 |
| Competing provider on the same request | 403 | ✅ own thread only | 403 | 404 on others' threads | 404 | 404 | 404 | 404 | 404 |
| Provider that did not receive it | 403 | 404 | 404 | 404 | 404 | 404 | 404 | 404 | 404 |
| Member of another factory | ✅ (their own) | 404 | 404 | 404 | 404 | 404 | 404 | 404 | 404 |
| IMC admin | 403 | ✅ statuses only | 403 | 403 | 403 | **403** | 403 | 403 | 403 |

State checks come after authorization: an allowed action in the wrong state gets 409 ([workflows.md](workflows.md)).

### Agreements and contract drafts (Phase 7 domain, ADR-017)

| Actor | list | view agreement / contract | terms, price, training plan | draft a contract | cancel a draft |
| --- | --- | --- | --- | --- | --- |
| Factory party | ✅ own | ✅ | ✅ | ✅ | ✅ |
| Provider party | ✅ own | ✅ | ✅ | ✅ | ✅ |
| Competing provider, other factory | empty list | 404 | — | 404 | 404 |
| IMC admin | ✅ all | ✅ | **hidden** | 403 | 403 |

### Invoices and payments (ADR-017)

| Actor | view, list | draft, edit lines, issue, cancel | start a payment |
| --- | --- | --- | --- |
| Provider party | ✅ | ✅ when the provider is the configured issuer, otherwise 403 | 403 |
| Factory party | ✅ | 403 | ✅ |
| IMC admin | ✅ all | ✅ when IMC is the configured issuer, otherwise 403 | 403 |
| Competing provider, other factory | 404 | 404 | 404 |

While no issuer is configured, anyone who can see the agreement gets 409 `policy_not_configured` instead of a permission answer.

### Accounts (users)

| Actor | list / create | view | update |
| --- | --- | --- | --- |
| IMC admin | ✅ | ✅ any | ✅ any (except deactivating themselves → 422; and the last active administrator cannot be removed → 422) |
| The account itself | 403 | ✅ | 403 |
| Colleague in the same organization | 403 | ✅ | 403 |
| Member of another organization | 403 | 404 | 404 |

### Audit log

| Actor | list |
| --- | --- |
| IMC admin | ✅ |
| Factory or provider member | 403 |

### Always available to any authenticated, active account
- `GET /api/v1/me`: own profile, organization and permissions.
- `POST /api/v1/auth/logout`.

## Rules for new endpoints
1. Add a policy method that checks a **permission** for platform-wide access and **ownership** (the user's own organization) for member access.
2. Return `Response::denyAsNotFound()` when the actor may not see the record. Return `Response::deny()` (403) only when they may see it but not perform the action.
3. Authorize in the Form Request `authorize()` (it runs before validation) or with `Gate::authorize()` in the controller.
4. Add the rows to the policy matrix test and one HTTP test per refused actor type.

## Onboarding (ADR-019)

- **Visitors** may call only `GET /registration/options` and the two registration endpoints (rate limited per IP).
- **Organization members** upload and read their own organization's documents. A provider's members change verified legal information only through a change request (`requestLegalChange`), which they may cancel; IMC administrators get 403 on submitting one and edit directly instead.
- **Factory members** read an approved, eligible provider's logo through the directory, never its registration documents.

### Financial policies (ADR-023)

| Actor | list, view, history, resolve, preview | create policy, draft, edit, submit | approve, reject | withdraw scheduled, end active | archive draft or rejected | manual payment |
| --- | --- | --- | --- | --- | --- | --- |
| IMC admin (role only) | ✅ | 403 | 403 | 403 | 403 | 403 |
| Admin with `financial_policies.manage` | ✅ | ✅ | 403 | 403 | ✅ | 403 |
| Admin with `financial_policies.approve` | ✅ | 403 | ✅ unless they prepared it (403) | ✅ | 403 | 403 |
| Admin with `payments.record` | ✅ | 403 | 403 | 403 | 403 | ✅ |
| Factory / provider member | 403 (lists), 404 (records) | 403 / 404 | 404 | 404 | 404 | 403 party, 404 outsider |

Tested in `tests/Feature/Policies/FinancialPolicyPolicyTest.php` and `tests/Feature/Api/V1/FinancialPolicy*Test.php`.
