# API Endpoints (v1)

Every implemented endpoint. Conventions (envelope, error codes, headers, pagination) are in [api-conventions.md](api-conventions.md). Permissions are in [roles-permissions.md](roles-permissions.md). Every request below is also in the Postman collection (`postman/`).

Base: `/api/v1`. Authenticated endpoints need `Authorization: Bearer <token>`. All error responses use the standard envelope. Record IDs in paths (`{id}`) are whole numbers; anything else, such as `5abc`, is 404.

## Health

| Method & path | Auth | Success | Errors |
| --- | --- | --- | --- |
| `GET /health` | none; not rate limited | 200 `{"data":{"status":"ok","checks":{"database":"ok"}}}` | 503 `status: unavailable` |

## Authentication

| Method & path | Auth | Request | Success | Errors |
| --- | --- | --- | --- | --- |
| `POST /auth/login` | none | `email`, `password`, `device_name` (≤100) | 200 `data: {token_type: "Bearer", access_token, expires_at, user}` (`user` as in `GET /me`) | 422 generic `These credentials do not match our records.` (unknown email, wrong password, deactivated); 429 after 5 failures/min per email+IP or 20 per 15 min per account |
| `POST /auth/logout` | token | — | 204; revokes the current token | 401 |
| `POST /auth/forgot-password` | none | `email` | **202** `data.message` (identical for every email); the link is emailed by a queued job | 422 invalid email format; 429 (5/min per IP) |
| `POST /auth/reset-password` | none | `token`, `email`, `password`, `password_confirmation` | 200 `data.message`; sets the password, revokes all tokens, marks the email verified | 422 `errors.token`: `This password reset link is invalid or has expired.` (any failure); 422 `errors.password` policy (≥12 characters, ≤72 bytes, confirmed); 429 |

Reset and invitation links point to `{FRONTEND_URL}/reset-password?token=…&email=…`. The client implements that page and posts to `/auth/reset-password`. Links expire after 60 minutes.

## Public registration (ADR-019)

Multipart form data (`Content-Type: multipart/form-data`); lists are sent as `sectors[]`, `services[]`. Uploads are optional: `logo` (jpg/png/webp, ≤2 MB), `commercial_registration_document` and `tax_registration_document` (pdf/jpg/png, ≤5 MB); the content type is detected from the bytes.

| Method & path | Auth | Request | Success | Errors |
| --- | --- | --- | --- | --- |
| `GET /registration/options` | none | — | 200 `data: {sectors[], factory_sizes[], service_categories[] (with services), documents: {logo, legal}: {extensions, max_kb}}` | — |
| `POST /registration/factories` | none; `throttle:registration` (10/hour/IP) | `name`*, `legal_name`, `size`, `sectors[]`* (≥1), `contact_name`*, `contact_job_title`, `contact_email`*, `contact_phone`, `website`, `governorate`, `city`, `address`, `commercial_registration_number`, `tax_registration_number`, files | **202** `data.message` — identical whether or not the email already has an account. The factory, its first member and the documents are created by a queued job, which emails the set-password link (`/reset-password`). | 422 per field; 429 |
| `POST /registration/service-providers` | none; `throttle:registration` | `name`*, `legal_name`, `description` (≤5000), `representative_name`*, `job_title`, `email`*, `phone`, `website`, `dx_experience_years`, `governorate`, `city`, `address`, the two registration numbers, `sectors[]`, `services[]` (catalog codes), files | **202** as above. The provider starts `pending`; approval fields sent are ignored. | 422; 429 |

Registration numbers: digits (Latin or Arabic-Indic), letters, spaces, `/` and `-`, up to 50 characters.

## Organization documents (ADR-019)

| Method & path | Who | Request | Success | Errors |
| --- | --- | --- | --- | --- |
| `POST /factories/{id}/documents` | admin; members of that factory | multipart `type` (`logo`\|`commercial_registration`\|`tax_registration`), `file` | **201** `data: {id, type, status: "active", original_name, mime_type, size_bytes, uploaded_at}`; the previous file of the type becomes `superseded` | 401, 404 (others, before validation), 422 |
| `GET /factories/{id}/documents/{documentId}` | admin; members of that factory | — | 200 file (`inline` for a logo, `attachment` otherwise; `Cache-Control: private, no-store`) | 401, 404 |
| `POST /service-providers/{id}/documents` | admin; members of that provider | as above | 201 | 401, 404, 422; **409** for a member replacing a legal document after IMC approval (use a change request) |
| `GET /service-providers/{id}/documents/{documentId}` | admin; members of that provider | — | 200 file | 401, 404 (factories and competitors included) |
| `GET /provider-directory/{id}/logo` | whoever may open that directory profile | — | 200 image | 401, 403 (provider members), 404 (not visible, or no logo) |

`FactoryResource` and `ServiceProviderResource` include `documents: {logo, commercial_registration, tax_registration}` (each the metadata above or `null`). No storage path or public URL is ever returned.

## Provider legal change requests (ADR-019)

After IMC approval a member cannot change `legal_name`, `commercial_registration_number`, `tax_registration_number` (422 `IMC has verified this information. Submit a change request to change it.`) or the registration documents directly.

| Method & path | Who | Request | Success | Errors |
| --- | --- | --- | --- | --- |
| `GET /provider-change-requests` | admin (`service_providers.approve`) | `filter[status]` (default `pending`), `page`, `per_page` | 200 paginated, oldest first | 401, 403 |
| `GET /service-providers/{id}/change-requests` | admin; members of that provider | `page`, `per_page` | 200 paginated, latest first | 401, 404 |
| `POST /service-providers/{id}/change-requests` | **members of that provider** | multipart: any of the three legal fields, `commercial_registration_document`, `tax_registration_document`, `note` (≤2000) | **201** `data: {id, status: "pending", changes: {field: value}, documents[], note, requested_by, service_provider: {current values}, ...}` | 401, 403 (admin), 404, 409 (provider not approved yet, or a request already pending), 422 (`changes`: nothing to change) |
| `POST /service-providers/{id}/change-requests/{requestId}/approve` | admin | — | 200; values and documents applied, previous documents superseded; provider stays approved | 403 (member), 404, 409 (not pending) |
| `POST .../{requestId}/reject` | admin | `reason`* (≤2000) | 200; documents kept as `rejected` | 403, 404, 409, 422 |
| `POST .../{requestId}/cancel` | members of that provider | — | 200 | 403 (admin), 404, 409 |

`ServiceProviderResource` adds `legal_information_verified` and `open_change_request`.

## Current user

| Method & path | Auth | Success |
| --- | --- | --- |
| `GET /me` | token | 200 `data: {id, name, email, role, organization: {type: "factory"\|"service_provider", id, name} \| null, is_active, created_at, permissions: [...]}` |

## Factories

| Method & path | Who | Request | Success | Errors |
| --- | --- | --- | --- | --- |
| `GET /factories` | admin | `filter[sector]` (sector code), `filter[size]` (size code), `filter[approval_status]`, `filter[readiness]`, `sort`, `search` (name, literal), `page`, `per_page` (1–100, default 15) | 200 paginated card summaries (see Phase 3 administration below; no legal or contact details) + `links` + `meta`; the `links` keep `per_page` and the filters | 401, 403 (members; before validation), 422 (unknown filter or value) |
| `POST /factories` | admin | `name` (≤255), `size` (optional; a code from `GET /reference/factory-sizes`), `sectors` (optional array of distinct sector codes, at most one per existing sector) | **201** factory | 401, 403, 422 (`The selected sectors.0 is invalid.`; `The sectors field must not have more than 4 items.`) |
| `GET /factories/{id}` | admin; member of that factory | — | 200 | 401, **404** for anyone else |
| `PATCH /factories/{id}` | admin; **members of that factory** (since P5) | `name`, `sectors` (both optional; sectors replaced only when sent); `size` (admin only; a size sent by a member is ignored, because the owner decision lets members edit only the name and sectors) | 200 | 401, 404 (others; checked before validation), 422 |
| `GET /factories/{id}` extras (ADR-019) | — | — | `legal_name`, `contact_*`, `website`, `governorate`, `city`, `address`, registration numbers, `documents`, `onboarding: {missing_profile_fields[], profile_complete, readiness_status: "not_started"\|"completed"}`; `PATCH` accepts the same profile fields from members | — |
| `GET /factories/{id}/readiness-assessments` | admin (`assessments.view_any`); members of that factory | `page`, `per_page` (≤100) | 200 paginated summaries, latest first | 401, 404 for anyone else |
| `POST /factories/{id}/readiness-assessments` | **members of that factory** | `questionnaire_version` (the current version, from `GET /readiness-questionnaire`); `answers`: a list with exactly one `{question_id, choice_id}` per question of that version | **201** full result | 401, 403 (admin), 404 (others; before validation), 409 (no questionnaire seeded), 422 (see below) |
| `GET /factories/{id}/readiness-assessments/{assessmentId}` | admin; members of that factory | — | 200 full result | 401, 404 (others, or an assessment of another factory) |
| `GET /factories/{id}/assessments` | admin; members of that factory | — | 200 `data[]`: **legacy** manual classifications ([ADR-016](decisions/ADR-016-manual-factory-classification.md), superseded), latest assessment date first | 401, 404 for anyone else |
| ~~`POST /factories/{id}/assessments`~~ | — | **Removed** ([ADR-018](decisions/ADR-018-digital-readiness-assessment.md)): manual classification is retired | 405 | — |

**Readiness assessment** ([ADR-018](decisions/ADR-018-digital-readiness-assessment.md)). The server alone computes the score and the category:
- **Total:** the sum of the points of the chosen choices (10–40).
- **Category:** the range of the version answered that contains the total. Version 1 ranges: `b4_automation` 10–17, `basic` 18–25, `advanced` 26–33, `smart` 34–40.
- **Client values ignored:** `score`, `total_score`, `category` or `points` sent by the client are never read.
- **422 when:**
  - an answer is missing or extra (`Answer every question of the questionnaire exactly once (10 answers).`);
  - a question is answered twice (`Each question may be answered only once.`);
  - a choice belongs to another question (`The selected choice does not belong to this question.`);
  - a question is not in the current version;
  - the version is not the current one (`The questionnaire has changed. Load the current version and answer it again.`).

**Result shapes:**
- **Summary:** `{id, factory_id, questionnaire_version, total_score, category: {code, name_en, name_ar, description_ar, min_score, max_score}, submitted_by: {id, name}, completed_at}`.
- **Full result:** the summary plus:
  - `min_score` and `max_score` (10 and 40);
  - `pillars: [{code, name_ar, name_en, score, max_score}]`;
  - `answers: [{question_id, question_code, question_number, choice_id, choice_code, choice_label_ar, points}]`;
  - `category.roadmap: {focus_ar, steps_ar, recommendations: [{position, text_ar, services: [{id, code, name_ar, category}]}]}`. A recommendation with `services: []` is one the catalog does not offer ([OQ-42](open-questions.md#oq-42)).

**History:** totals, categories and answer points are those recorded at submission, never recalculated. Assessments are never changed; a new assessment is a new entry.

Legacy classification: `{id, factory_id, classification_method: "manual", score: null, maturity_tier: {code, name_ar, name_en, readiness_band_ar, approved_path_ar, pathway_level: {...}}, justification, assessed_on, recorded_by: {id, name}, created_at}`.

Factory resource: `{id, name, size, sectors: [{code, name_ar, name_en}], current_readiness: {assessment_id, questionnaire_version, total_score, category: {code, name_en, name_ar}, completed_at} | null, created_at, updated_at}`.
- **Sector codes:** `food`, `chemical`, `engineering_metal`, `medical_pharmaceutical`.
- **`size`:** the declared company size, or null. The default list is `small`, `medium`, `large`; the list is configurable and the size is never derived (OQ-04).
- **`current_readiness`:** the latest completed readiness assessment, or null before the first. It replaced `current_classification` on 2026-10-03; a legacy manual classification never sets it.

## Service providers

The list, create and show endpoints follow the same contract as factories, at `/service-providers` and `/service-providers/{id}`. Members see only their own provider. Profile fields are the services workbook's ([ADR-014](decisions/ADR-014-catalog-and-provider-profiles.md)). The admin list takes `filter[approval_status]` (`pending` is IMC's review queue), `filter[sector]`, `filter[service]` and `search` (name, literal); members get 403 before validation.

| Method & path | Who | Request | Success | Errors |
| --- | --- | --- | --- | --- |
| `POST /service-providers` | admin | `name` (required, ≤255); optional: `representative_name`, `job_title`, `email`, `phone` (digits, `+`, `()`, `-` and spaces, 6–30 characters), `website` (http/https URL), `dx_experience_years` (0–100), `sectors[]` (sector codes), `services[]` (catalog service codes) | **201**; starts `pending` | 401, 403, 422 |
| `PATCH /service-providers/{id}` | admin; **members of that provider** | the same fields, all optional; lists are replaced only when sent; approval fields are ignored | 200 | 401, 404, 422 |
| `POST /service-providers/{id}/approval` | admin (`service_providers.approve`) | `decision` (`approved` \| `rejected` \| `suspended`), `reason` (required to reject or suspend, ≤2000) | 200 provider | 401, 403 (own member), 404, **409** (transition not allowed, see [workflows.md](workflows.md#1-provider-approval-owner-approved-approval-before-visibility-adr-014)), 422 (also when approving a profile that misses a field the owner made required, OQ-36: `The provider profile is missing required fields: email.`) |
| `POST /service-providers/{id}/review-request` | **members of that provider** (PROPOSED) | `note` (optional, ≤2000) | 200 provider, back to `pending` | 401, 403 (admin, who decides through `/approval`), 404 (others; before validation), **409** (only a `rejected` provider can ask), 422 (note too long; or a required profile field missing) |
| `GET /service-providers/{id}/evaluations` | admin (`service_providers.evaluate`) | — | 200 `data[]`, latest evaluation date first | 401, 403 (own member), 404 (others) |
| `POST /service-providers/{id}/evaluations` | admin (`service_providers.evaluate`) | `summary` (≤5000), `evaluated_on` (`YYYY-MM-DD`, today or earlier), `criteria`: an object with **every** criterion code of the current matrix (`GET /reference/evaluation-criteria`), each `{note (≤2000), score}`. `score` is **refused** while no scale is approved (OQ-13) and **required** (0 to the scale, ≤2 decimals) once one is | **201** evaluation | 401, 403 (own member), 404 (others; before validation), 422 |

Evaluation (DOC §6, OQ-13 interim): `{id, service_provider_id, criteria_version, summary, evaluated_on, scoring: "not_configured" | "scored", scale_max, weighted_total, pass_mark, meets_pass_mark, criteria: [{code, name_ar, weight_percent, score, note}], recorded_by, created_at}`. While no scale is approved, `scoring` is `not_configured` and every score, the total and the pass-mark result are null. With an approved scale, `weighted_total` = Σ weight × score / scale, rounded half up to 2 decimals (out of 100); `meets_pass_mark` is shown only with an approved pass mark and is **informational**: approval stays a manual IMC decision. Evaluations are never changed; a re-evaluation is a new entry.

Provider resource: `{id, name, representative_name, job_title, email, phone, website, dx_experience_years, sectors[], services: [{id, code, name_ar, category: {id, code, name_ar, name_en}}], approval: {status, reason, changed_at}, created_at, updated_at}`.

## Reference data

The source document's reference data, for any signed-in account. Not paginated (bounded lists).

| Method & path | Success |
| --- | --- |
| `GET /reference/sectors` | 200 `data[]`: `{code, name_ar, name_en}` in source order |
| `GET /reference/factory-sizes` | 200 `data[]`: `{code, name_ar}` (configurable list, OQ-04) |
| `GET /reference/maturity-tiers` | 200 `data[]`: `{code, name_ar, name_en, readiness_band_ar, operational_state_ar, approved_path_ar, expected_impact_ar, pathway_level: {code, name_ar, pathway: {code, name_ar}}}`. No score range: the source has none (OQ-06). |
| `GET /reference/pathways` | 200 `data[]`: `{code, name_ar, name_en, target_group_ar, levels: [{code, name_ar, subtitle_ar, name_en, target_group_ar, scope_items: [{group_ar, group_en, text_ar}], provider_requirements: [text]}]}` (DOC §3; whether the requirements are hard gates is open, OQ-14) |
| `GET /reference/evaluation-criteria` | 200 `data[]`: `{version, code, name_ar, name_en, sub_elements_ar, weight_percent, verification_ar}` of the current matrix (DOC §6: weights 30/25/20/15/10) |
| `GET /readiness-questionnaire` | 200 `data`: the current readiness questionnaire (RDA, [ADR-018](decisions/ADR-018-digital-readiness-assessment.md)): `{version, title_ar, title_en, min_score, max_score, pillars: [{code, name_ar, name_en, questions: [{id, code, number, text_ar, choices: [{id, code, label_ar, text_ar, points}]}]}], categories: [{code, name_en, name_ar, description_ar, min_score, max_score, roadmap: {focus_ar, steps_ar, recommendations[]}}]}`. 404 if none is seeded. |

## Catalog

Reference data from the services workbook: 7 categories and 42 services. Any signed-in account can read it. Not paginated (bounded size).

| Method & path | Request | Success |
| --- | --- | --- |
| `GET /catalog/categories` | — | 200 `data[]`: `{id, code, name_ar, name_en, services: [{id, code, name_ar}]}` in workbook order |
| `GET /catalog/categories/{id}` | — | 200, one category with its services |
| `GET /catalog/services` | `filter[category]` (category code); `filter[eligible]=1`, for **factory members only** (422 for others): the services that at least one provider eligible for their factory offers; `filter[recommended]=1`, for **factory members whose factory has a readiness assessment** (422 otherwise): the services the roadmap recommends for the factory's current readiness category | 200 `data[]` with each service's `category` |
| `GET /catalog/services/{id}` | — | 200 |

## Provider directory

| Method & path | Who | Request | Success | Errors |
| --- | --- | --- | --- | --- |
| `GET /provider-directory` | factory members (providers eligible for their factory: **approved and targeting one of its sectors**); admin (every approved provider) | `filter[service]`, `filter[category]`, `filter[sector]` (a factory member: only their own sectors), `filter[recommended]=1` (factory members whose factory has a readiness assessment; 422 otherwise: only the eligible providers that offer a service recommended for the factory's current readiness category, never a provider that is not eligible), `search` (name, literal), `sort` (`name`, `-name`, `dx_experience_years`, `-dx_experience_years`), `page`, `per_page` (≤100) | 200 paginated `{id, name, website, dx_experience_years, sectors[], services[]}`. Contact details (`representative_name`, `job_title`, `email`, `phone`) only when the owner enables them ([OQ-37](open-questions.md#oq-37); off by default). | 401, 403 (provider members), 422 (unknown filter or sort) |
| `GET /provider-directory/{id}` | the same | — | 200, the same public profile | 401, 403 (provider members), **404** for a provider the user cannot find in the directory (not approved, or not eligible for their factory) |

## Service requests and negotiation

The factory-initiated marketplace ([ADR-015](decisions/ADR-015-marketplace-requests.md); states in [workflows.md](workflows.md#2-marketplace-request-model-owner-approved-states-proposed-oq-38-adr-015)).
- **Statuses change only through the action endpoints below.**
- **An action in the wrong state gets 409.**
- **An `agreed` thread is not a contract, invoice or payment.**
- **Authorization comes before validation:** anyone who may not see the request or thread gets 404, even when the input is invalid.
- **While IMC has suspended the provider, the thread is paused** (PROPOSED, [OQ-40](open-questions.md#oq-40)): accepting the request, messages, offers and accepting an offer get 409 `This provider is not approved by IMC at the moment, so this negotiation is paused.` Decline, withdraw and cancel still work.

| Method & path | Who | Request | Success | Errors |
| --- | --- | --- | --- | --- |
| `POST /service-requests` | factory member | `service` (code), `title` (≤200), `need` (≤5000), `requirements` (optional, ≤5000), `provider_ids[]` (1–20, each approved, offering the service and targeting one of the factory's sectors) | **201** request, `open`, with one `pending` provider request per provider | 401, 403 (providers, admin), 422 |
| `GET /service-requests` | factory member (own factory); admin (all) | `filter[status]` (`open` \| `awarded` \| `cancelled`), `page`, `per_page` | 200 paginated, with each thread's status | 401, 403 (providers) |
| `GET /service-requests/{id}` | the factory; providers it was sent to; admin | — | 200. **A provider sees only its own thread** in `provider_requests`. `active_provider_count`: the listed threads still pending or negotiating (0 on an open request means every provider declined or was withdrawn). | 401, 404 |
| `POST /service-requests/{id}/cancel` | the factory | `reason` (optional, ≤2000) | 200; open threads become `closed` | 401, 403, 404, 409 (not open) |
| `POST /service-requests/{id}/providers` | the factory (PROPOSED, [OQ-38](open-questions.md#oq-38)) | `provider_ids[]` (1–20; each eligible as at creation, not already on the request; at most 20 providers per request in total) | 200 the request, with one new `pending` thread per provider | 401, 403 (providers on it, admin), 404 (others; before validation), **409** (request not open), 422 |
| `GET /provider-requests` | provider (its inbox); factory (its threads); admin | `filter[status]`, `filter[service_request]`, `page`, `per_page` | 200 paginated `{id, provider, status, status_reason, status_changed_at, agreed_offer_id, service_request: {…}}` | 401, 422 |
| `GET /provider-requests/{id}` | the two parties; admin | — | 200 | 401, 404 |
| `GET /provider-requests/{id}/history` | the two parties; admin | — | 200 `data[]` oldest first: `{from, to, reason, actor: {id, name, side: "factory" \| "provider" \| null} \| null, at}`; the first entry is the creation (`from: null`). History is kept from 2026-10-03 on; earlier changes are only in the audit log. | 401, 404 |
| `POST /provider-requests/{id}/accept` | the provider | — | 200 `accepted`: negotiation opens | 401, 403, 404, 409 (not pending, or the provider is suspended) |
| `POST /provider-requests/{id}/decline` | the provider | `reason` (optional) | 200 `declined` | 401, 403, 404, 409 |
| `POST /provider-requests/{id}/withdraw` | the factory | `reason` (optional) | 200 `withdrawn` | 401, 403, 404, 409 |
| `GET /provider-requests/{id}/messages` | the two parties (admin: 403) | `page`, `per_page` | 200 paginated, oldest first: `{id, author_side, author: {id, name}, body, created_at}` | 401, 403, 404 |
| `POST /provider-requests/{id}/messages` | the two parties | `body` (≤5000) | **201** | 401, 403, 404, 409 (not negotiating, or the provider is suspended), 422, **429** (30 per minute) |
| `GET /provider-requests/{id}/offers` | the two parties (admin: 403) | — | 200 `data[]` newest first: `{id, version, state (current \| expired \| lapsed \| superseded \| accepted), scope, deliverables, duration_days, valid_until, price: {amount: "250000.00", currency: "EGP"}, author, created_at}` | 401, 403, 404 |
| `POST /provider-requests/{id}/offers` | the provider | `based_on_version` (the latest version seen; `null` for the first), `scope`, `deliverables` (≤5000 each), `duration_days` (1–3650), `valid_until` (optional `YYYY-MM-DD`, today or later: the last day the offer may be accepted, as the provider sets it), `price: {amount, currency: "EGP"}` (amount: decimal, ≤2 places, 0–999999999999.99; send it as a string) | **201** the next version | 401, 403, 404, **409** (not negotiating, the provider is suspended, or a newer version exists), 422, **429** (10 per minute) |
| `POST /provider-requests/{id}/offers/{offerId}/accept` | the factory | — | 200 the offer (`state: accepted`); the thread becomes `agreed` and the request `awarded`. With the single-award rule (the PROPOSED default, `JAHEZ_MARKETPLACE_SINGLE_AWARD`, [OQ-38](open-questions.md#oq-38)) the other open threads become `closed`; without it they continue and their offers can be accepted too. | 401, 403, 404 (offer of another thread), **409** (not the latest version, the offer expired, the request takes no further acceptance, thread not negotiating, provider suspended) |

## Agreements and contract drafts

[ADR-017](decisions/ADR-017-agreements-contracts-billing.md). An agreement is created **only** when the factory accepts an offer, in the same transaction, and is never changed. A contract is always a **draft and not legally binding** (OQ-17 interim): there is no signing or approval step. Threads show their `agreement_id` once agreed.

| Method & path | Who | Request | Success | Errors |
| --- | --- | --- | --- | --- |
| `GET /agreements` | parties (their own); admin (`agreements.view_any`: all, without terms) | `page`, `per_page` | 200 paginated, newest first | 401, 422 |
| `GET /agreements/{id}` | the factory and the provider; admin (without terms) | — | 200 | 401, 404 |
| `POST /agreements/{id}/contracts` | **either party** (PROPOSED) | `knowledge_transfer: {trainees (≥2, DOC §6), training_plan (≤10000)}`, `notes` (optional, ≤5000) | **201** the next draft version | 401, 403 (admin), 404 (others; before validation), **409** (a draft is already in force; cancel it first), 422 (`DOC §6 requires training at least 2 IMC engineers.`) |
| `GET /contracts` | parties (their own); admin (status only) | `filter[status]` (`draft` \| `cancelled`), `filter[agreement]`, `page`, `per_page` | 200 paginated, newest first | 401, 422 |
| `GET /contracts/{id}` | the two parties; admin (status only) | — | 200 | 401, 404 |
| `POST /contracts/{id}/cancel` | either party | `reason` (optional, ≤2000) | 200 `cancelled` | 401, 403 (admin), 404, **409** (not a draft) |

Agreement: `{id, service_request_id, provider_request_id, factory: {id, name}, provider: {id, name}, service: {code, name_ar}, binding: false, terms: {offer_id, offer_version, scope, deliverables, duration_days}, price: {amount, currency}, contract: {id, version, status} | null, concluded_by, concluded_at}`. `terms` and `price` are shown to the two parties only.

Contract: `{id, agreement_id, version, status, status_reason, binding: false, legal_status: "draft_not_binding", signature: {status: "not_available", decision_needed: "OQ-17"}, knowledge_transfer: {trainees, training_plan, commitment_memo: {status: "not_available"}}, notes, drafted_by, status_changed_at, created_at}`. `knowledge_transfer` and `notes` are shown to the two parties only.

## Billing and payments

[ADR-017](decisions/ADR-017-agreements-contracts-billing.md), [ADR-023](decisions/ADR-023-financial-and-contract-policies.md). Every operation that needs a policy that is not approved and in effect answers **409** `policy_not_configured` with `decision_needed`, `reason_ar` and `missing_policies`. Drafting needs an approved invoicing policy; issuing also needs tax and payment-terms policies (and a revenue share when the invoicing policy requires one). `GET /billing/configuration` says which kinds have a policy in effect anywhere; `GET /agreements/{id}/financial-readiness` says, per agreement, what is possible and why not. Amounts are decimal strings in the invoice currency (EGP).

| Method & path | Who | Request | Success | Errors |
| --- | --- | --- | --- | --- |
| `GET /billing/configuration` | any signed-in account | — | 200 `{invoice_drafting, invoice_issuing, revenue_share, payments, refund_initiation, payouts}`, each `{available, decision_needed}` | 401 |
| `POST /agreements/{id}/invoices` | the configured issuer: IMC (`invoices.manage`) or the agreement's provider | — | **201** draft: one line, the agreed service at the agreed price | 401, 403 (not the issuer), 404, **409** (`policy_not_configured`: no issuer decided; or the agreement already has an invoice that is not cancelled) |
| `GET /invoices` | parties (their own); admin (`invoices.view_any`) | `filter[status]` (`draft` \| `issued` \| `paid` \| `refunded` \| `cancelled`), `filter[agreement]`, `page`, `per_page` | 200 paginated | 401, 422 |
| `GET /invoices/{id}` | the two parties; admin | — | 200 | 401, 404 |
| `POST /invoices/{id}/lines` | the issuer | `description` (≤500), `quantity` (whole number, 1–1,000,000), `unit_amount` (decimal string, ≤2 places) | 200 the invoice, subtotal updated | 401, 403, 404, **409** (not a draft), 422 (also when the total would exceed 999,999,999,999.99) |
| `DELETE /invoices/{id}/lines/{lineId}` | the issuer | — | 200 the invoice | 401, 403, 404 (line of another invoice), 409 |
| `POST /invoices/{id}/issue` | the issuer | — | 200: number (`PREFIX-000001`, gap-free), tax (half up on the subtotal), total, revenue share (only if configured; informational) | 401, 403, 404, **409** (`policy_not_configured`: numbering or tax undecided; or not a draft), 422 (nothing to pay) |
| `POST /invoices/{id}/cancel` | the issuer | `reason` (optional) | 200 `cancelled` (drafts only) | 401, 403, 404, **409** (an issued invoice: `policy_not_configured`, credit notes undecided) |
| `GET /invoices/{id}/payments` | the two parties; admin | — | 200 `data[]` | 401, 404 |
| `POST /invoices/{id}/payments` | the factory party | header **`Idempotency-Key`** (8–100 of `A-Za-z0-9_-`) | **201** a `pending` payment of the total, with `checkout_url`; **200** the same payment for a repeated key | 401, 403, 404, **409** (`policy_not_configured`: no gateway; invoice not issued; a payment already pending or succeeded), 422 (key), **503** (gateway could not start it; the payment is `failed`) |
| `POST /invoices/{id}/manual-payments` | admin with `payments.record` and `invoices.view_any` | header **`Idempotency-Key`**; `amount` (decimal string > 0), `received_on` (YYYY-MM-DD, not future, not before issue), `reference` (≤100, unique), `evidence_note` (optional) | **201** a `succeeded` payment with `method: manual`; the invoice becomes `partially_paid` or `paid`; **200** for a repeated key | 401, 403, 404, **409** (`policy_not_configured`: the invoicing policy of the invoice allows no manual entries, or a legacy invoice; not issued; a gateway payment pending; duplicate reference), 422 (more than outstanding; part payment the terms forbid) |
| `GET /agreements/{id}/financial-readiness` | whoever may see the agreement | — | 200 `{contract_drafting, invoice_drafting, invoice_issuing, gateway_payment, contract_signature, payouts}`, each `{available, reasons: [{code, message_ar, decision_needed}]}` | 401, 404 |
| `POST /payment-gateways/{gateway}/callback` | the payment gateway (public; verified by the adapter) | the gateway's signed body | 200 `{outcome}`: `applied`, `duplicate`, `no_change`, `unknown_payment`, `amount_mismatch` or `ignored_transition` | **400** (not verified; nothing recorded), 404 (not the configured gateway) |

Invoice (ADR-023 additions: `type`, `due_date`, `is_overdue`, `payer`, `policy_basis` (`legacy` | `policy`), `policy_versions` {invoicing, tax, payment_terms, revenue_share}, `fees`, `amount_paid`, `outstanding`, `calculation` (the frozen server breakdown); status adds `partially_paid`): `{id, agreement_id, number, status, status_reason, issuer, currency, lines: [{id, position, description, quantity, unit_amount, line_amount}], subtotal, tax: {rate_percent, amount} | null, total, revenue_share: {status: "not_configured", decision_needed: "OQ-15"} | {status: "calculated", rate_percent, amount}, issued_at, paid_at, status_changed_at, created_at}`.

Payment: `{id, invoice_id, gateway, gateway_reference, status (pending | succeeded | failed | cancelled | refunded), amount, currency, checkout_url, failure_reason, status_changed_at, created_at}`. A payment becomes `succeeded` only from verified gateway evidence; then its invoice becomes `paid`.

## Users (accounts)

| Method & path | Who | Request | Success | Errors |
| --- | --- | --- | --- | --- |
| `GET /users` | admin | `page`, `per_page` | 200 paginated | 401, 403 |
| `POST /users` | admin | `name`, `email` (unique), `role` (`imc_admin` \| `factory_member` \| `provider_member`), `factory_id` (required for factory members, otherwise prohibited), `service_provider_id` (required for provider members, otherwise prohibited) | **201** user; a queued invitation email is sent | 401, 403, 422, 409 (concurrent duplicate) |
| `GET /users/{id}` | admin; the account itself; colleagues in the same organization | — | 200 | 401, **404** for anyone else |
| `PATCH /users/{id}` | admin | `name`, `is_active` (both optional). Other fields, including `role` and organization, are ignored. | 200; deactivation revokes all tokens and reset links | 401, 403, 404, 422 (own deactivation, or the last active administrator) |

User resource: `{id, name, email, role, organization, is_active, created_at}`. Passwords, tokens and remember tokens are never returned.

## Audit log

Append-only record of security events ([ADR-012](decisions/ADR-012-audit-log.md)). Read-only through the API.

| Method & path | Who | Request (query) | Success | Errors |
| --- | --- | --- | --- | --- |
| `GET /audit-logs` | admin (`audit_logs.view`) | All optional: `event` (an event name, below), `actor_user_id`, `subject_type` (`user` \| `factory` \| `service_provider`) **with** `subject_id`, `from` / `to` (`YYYY-MM-DD`, whole UTC days, inclusive), `per_page` (1–100, default 50), `cursor` (from `meta.next_cursor` or `links.next`) | 200 newest first: `data[]`, `links` (keep the filters), `meta: {path, per_page, next_cursor, prev_cursor}` | 401, 403 (members), 422 (unknown filter value, `subject_type`/`subject_id` alone, `to` before `from`, a cursor the API did not issue: `The cursor is invalid.`) |

Entry: `{id, event, actor: {id, name, email} | null, subject: {type, id} | null, ip_address, request_id, metadata, created_at}`. `actor` is null for anonymous events (failed or throttled logins, reset requests, console actions).

| Event | Actor / subject | Metadata |
| --- | --- | --- |
| `auth.login_succeeded` | the user / the user | `credential_id`, `device_name` |
| `auth.login_failed` | — / the account if it exists | `email` (as submitted), `reason`: `unknown_account` \| `wrong_password` \| `deactivated_account` |
| `auth.login_throttled` | — / — | `email`, `limit`: `address` \| `account` (first refusal of each lock period only) |
| `auth.logged_out` | the user / the user | `credential_id` |
| `auth.password_reset_requested` | — / the account | `status` (written by the queue job, with the requester's IP) |
| `auth.password_reset_completed` | the user / the user | — |
| `user.created` | admin / the account | `role`, `factory_id`, `service_provider_id` |
| `user.renamed` | admin / the account | `from`, `to` |
| `user.deactivated`, `user.reactivated` | admin / the account | — |
| `user.administrator_created_from_console` | — / the new administrator | — |
| `factory.created`, `service_provider.created` | admin / the organization | `name`, `sectors` |
| `factory.updated`, `service_provider.updated` | admin / the organization | only what changed: `name: {from, to}`, `sectors: {from, to}` |

## Readiness questionnaire versions (ADR-018 addendum)

| Method & path | Who | Request | Success | Errors |
| --- | --- | --- | --- | --- |
| `GET /readiness-questionnaires` | admin (`readiness_questionnaires.manage`) | — | 200 `data[]: {id, version, title_ar, title_en, source_ref, status: "draft"\|"current"\|"retired", published_at, assessments_count, created_by, updated_by, published_by ({id, name} or null; null for the seeded version 1), created_at, updated_at}`, newest first | 401, 403 |
| `GET /readiness-questionnaires/{id}` | admin | — | 200 with `definition` (the questionnaire as factories see it, plus each category's `focus_ar`, `steps_ar`) | 401, 403, 404 |
| `POST /readiness-questionnaires` | admin | — | **201** a draft copied from the current version | 409 (a draft exists; no current version) |
| `PUT /readiness-questionnaires/{id}` | admin | `title_ar`*, `title_en`, `pillars[5]: {code, name_ar, name_en, questions[2]: {code, text_ar, choices[4]: {code, label_ar, text_ar, points 1–4}}}`, `categories[4]: {code, name_ar, name_en, description_ar, min_score, max_score, focus_ar, steps_ar, recommendations?[1–30]: {text_ar ≤500, services: [catalog codes]}}` (ADR-018 addendum 2) | 200; `updated_by` set | 409 (published version), 422: a pillar, question or choice code that is not the draft's own (added, removed or moved), choice points other than 1, 2, 3 and 4 once each, empty or repeated labels in a question, duplicate codes, ranges with a gap or an overlap or not covering 10–40, an empty roadmap, an unknown catalog code. Without `recommendations` a category keeps its lines |
| `POST /readiness-questionnaires/{id}/publish` | admin | — | 200; the draft becomes current (`published_by` set), the previous version is retired; earlier assessments are never recalculated | 409, 422 (`definition`: the shape, the ranges, a level without a recommendation) |
| `DELETE /readiness-questionnaires/{id}` | admin | — | 204 | 409 (published) |

`POST /factories/{id}/readiness-assessments` accepts an optional `Idempotency-Key` header: the same key returns the stored assessment with **200** instead of creating another.

Each answer of a stored assessment carries `question_text_ar`, `choice_label_ar` and `choice_text_ar` as they were when it was submitted (a snapshot, ADR-018 addendum 2), with its `points`. No endpoint changes a stored score or category; a correction workflow is not built ([OQ-51](open-questions.md#oq-51)).
## Phase 2 portals (ADR-020)

| Method and path | Who | Notes |
| --- | --- | --- |
| `POST /agreements/{id}/review` | IMC (`agreements.review`) | `decision`: `approved` or `rejected` (+ `reason`, required to reject); final, 409 on repeat; parties 403 |
| `GET /agreements?filter[review_status]=&filter[contract_status]=&search=` | parties, IMC | `imc_review`, `next_step` in each agreement |
| `POST /provider-requests/{id}/read` | the two parties | marks the thread read up to its latest message; IMC 403 |
| `GET /provider-requests?search=&sort=&filter[unread]=&filter[service]=` | parties, IMC | threads carry `unread_messages_count`, `last_message_at`, `latest_offer_version`, `agreement` statuses |
| `GET /service-requests?search=&sort=&filter[service]=&filter[thread_status]=` | factory, IMC | threads carry the same activity fields |
| `GET /notifications?filter[unread]=` | any account | own notifications, `meta.unread_count` |
| `POST /notifications/{id}/read`, `POST /notifications/read-all` | any account | another account's id is 404 |
| `PATCH /me` | any account | `name`, `email_notifications` only |
| `GET /service-listings?search=&filter[category\|service\|recommended\|promoted\|approval_status\|provider]=` | factory (eligible only), provider (own), IMC | promoted first, `promotion.label` «إعلان»; `filter[recommended]` needs an assessment (422) |
| `GET/POST /promotions`, `PATCH /promotions/{id}`, `POST /promotions/{id}/end` | IMC (`promotions.manage`) | the provider must offer the service; never deleted |
| `GET /reports/marketplace?from=&to=&filter[service]=` | factory, provider (own), IMC | UTC days, ≤ 2 years; counts, rates with base, issued invoice totals per currency |
| `GET /factory-change-requests` | IMC (`factories.update`) | pending queue (`filter[status]`) |
| `GET/POST /factories/{id}/change-requests`, `POST .../{changeRequest}/approve\|reject\|cancel` | members (submit, cancel), IMC (decide) | multipart; recorded values stay until approval |
| `GET /invoices?filter[service]=&filter[from]=&filter[to]=&filter[counterparty]=` | parties, IMC | invoices carry `parties`, `type`, `due_date` (null) |

## Phase 3 administration (ADR-021)

| Method and path | Who | Notes |
| --- | --- | --- |
| `POST /service-providers/{id}/approval` | IMC (`service_providers.approve`) | `decision` adds `changes_requested` (reason required) |
| `POST /service-providers/{id}/review-request` | the provider's members | also after `changes_requested` |
| `GET /service-providers?sort=newest\|oldest\|name\|recently_decided&filter[category]=&filter[listing_status]=` | IMC | `service_listings` (each service with its review) in every provider; `missing_required_fields` on `GET /service-providers/{id}` only |
| `POST /service-providers/{id}/services/{catalogService}/review` | IMC (`service_listings.review`) | `decision`: `approved`, `rejected`, `suspended` (+ `reason`, required except to approve); 404 when the provider does not list the service; 409 on an invalid transition |
| `GET /service-listings?filter[listing_status]=&sort=newest` | provider (own), IMC | each listing carries `review` (status, reason, changed_at, submitted_at); factories only ever get approved listings and no `review` |
| `POST /factories/{id}/approval` | IMC (`factories.approve`) | `decision`: `approved`, `rejected`, `changes_requested`, `suspended` (+ `reason`, required except to approve); 422 while a configured onboarding field is empty; never changes the readiness classification |
| `POST /factories/{id}/review-request` | the factory's members | `note` optional; from `rejected` or `changes_requested` back to `pending` |
| `GET /factories?sort=newest\|oldest\|name\|recently_decided&filter[approval_status]=&filter[readiness]=none\|b4_automation\|basic\|advanced\|smart` | IMC | card summaries (`FactorySummaryResource`, ADR-021 amendment): `id, name, size, governorate, city, sectors, logo {id, uploaded_at}, onboarding, current_readiness, approval, profile_completion {filled, total, missing[] field names}, service_requests_count, created_at, updated_at`. No legal name, contact details, address, registration numbers or documents other than the logo; those are on `GET /factories/{id}` |
| `POST /service-requests`, `POST /service-requests/{id}/providers` | factory members | 409 while the factory is not approved and `JAHEZ_FACTORY_APPROVAL_REQUIRED` is on (OQ-46) |
| `GET /readiness-assessments?search=&sort=newest\|oldest\|score_desc\|score_asc&filter[category\|version\|current\|from\|to\|score_min\|score_max\|factory]=` | IMC (`assessments.view_any`) | submitted assessments with `factory`; `score_min`/`score_max` inclusive (422 when the maximum is below the minimum), `factory` a factory id; answers through `GET /factories/{id}/readiness-assessments/{assessment}` |
| `GET /readiness-analytics?filter[from]=&filter[to]=` | IMC (`assessments.view_any`) | counts, completion rate, current classification per category, submissions per month and version, current questionnaire structure and its problems |
| `GET /review-summary` | IMC | the size of every review queue in one call: providers and factories per approval status, listings per review status, open change requests |

## Announcements and listing resubmission (ADR-022)

| Method and path | Who | Notes |
| --- | --- | --- |
| `GET /public/announcements` | anyone, no token | live announcements (published, started, not ended), by `sort_order` then newest; public fields only; at most 30 |
| `GET /public/announcements/{id}`, `GET /public/announcements/{id}/cover` | anyone, no token | 404 unless live (and, for the cover, unless it has one) |
| `GET /announcements?filter[published]=` | IMC (`announcements.manage`) | every announcement with `state` (`draft`, `scheduled`, `live`, `ended`), `sort_order`, `starts_at`, `published_at` |
| `POST /announcements`, `PATCH /announcements/{id}` | IMC | `title`*, `description`*, `color`* (`green`, `blue`, `orange`, `red`, `beige`, `purple`), `badge_text`, `link_path` (a path of the web client only), `tags` (≤ 5), `countdown_text`, `cover_alt`, `sort_order` (0–1000), `starts_at`, `ends_at` (after `starts_at`); 422 otherwise |
| `POST /announcements/{id}/publish`, `/unpublish` | IMC | 409 when already in that state |
| `DELETE /announcements/{id}` | IMC | 204 for a draft; 409 while published |
| `GET\|POST\|DELETE /announcements/{id}/cover` | IMC | multipart `file` (jpg, png, webp, logo size limit); replaces the previous file |
| `POST /service-providers/{id}/services/{catalogService}/resubmit` | the provider's members | optional `note`; `rejected → pending`; 409 for any other status; IMC 403, others 404 |

## Financial and contract policies (ADR-023)

All need `financial_policies.view` (IMC administrators); members get 403 on lists and 404 on records. Values are validated per kind; percentages and amounts are decimal **strings**. The client never sends a status, a calculated amount or an approval.

| Method & path | Who | Request | Success | Errors |
| --- | --- | --- | --- | --- |
| `GET /financial-policies` | view | `filter[kind]`, `filter[scope_type]`, `page`, `per_page` | 200 paginated, each with `current_version` (in effect today) and `open_version` | 401, 403, 422 |
| `POST /financial-policies` | manage | `kind`, `scope_type`, `scope_id` (service or provider) or `scope_code` (sector), `name_ar`, `description_ar`, `parameters`, `effective_from`, `effective_to`, `change_reason` | **201** the first draft version | 401, 403, **409** (policy exists for the kind and scope), 422 |
| `GET /financial-policies/{id}` | view | — | 200 with `versions` | 401, 404 |
| `POST /financial-policies/{id}/versions` | manage | `parameters`, `effective_from`, `effective_to`, `change_reason` | **201** a draft | 401, 403, 404, **409** (another version open), 422 |
| `GET /financial-policy-versions/{id}` | view | — | 200 with `actions` for the caller | 401, 404 |
| `PATCH /financial-policy-versions/{id}` | manage | any draft field | 200 | 401, 403, 404, **409** (not a draft), 422 |
| `POST /financial-policy-versions/{id}/submit` | manage | — | 200 `pending_approval` | 401, 403, 404, **409** (not a draft; overlaps an approved version), 422 (start in the past) |
| `POST /financial-policy-versions/{id}/approve` | approve, not the preparer | `reason` (optional note) | 200 `approved`; the version in effect on its start date is `superseded` | 401, 403, 404, **409** (not pending; start passed; overlap) |
| `POST /financial-policy-versions/{id}/reject` | approve, not the preparer | `reason` (required) | 200 `rejected` | 401, 403, 404, 409, 422 |
| `POST /financial-policy-versions/{id}/archive` | manage (draft, rejected) or approve (scheduled) | `reason` (required) | 200 `archived` | 401, 403, 404, **409** (in effect: end it; superseded another), 422 |
| `POST /financial-policy-versions/{id}/end` | approve | `effective_to` (today or later, before the current end), `reason` | 200 `ended` | 401, 403, 404, 409, 422 |
| `GET /financial-policy-versions/{id}/history` | view | — | 200 audit entries of the version and its policy: `{event, actor {id, name}, metadata, created_at}` (no IP or e-mail) | 401, 404 |
| `POST /financial-policy-versions/{id}/preview` | view | `amount` | 200 the calculation under this version alone (tax, revenue share), or the due date, first number, clause count | 401, 404, 422 |
| `GET /financial-policies/resolve` | view | `kind`, `catalog_service` (id), `sectors[]` (codes), `service_provider` (id), `date` | 200 `{kind, date, version | null, reason_ar}` | 401, 403, **409** (two sector policies apply, OQ-47), 422 |
| `POST /financial-policies/preview` | view | `amount` and the same context | 200 `{calculation, due_date, policy_versions, missing_ar[]}`; nothing stored | 401, 403, **409** (no tax policy applies), 422 |
