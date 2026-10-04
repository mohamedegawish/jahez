# Open Questions

Decisions that need owner (business, product, legal or technical) input. **Do not guess any of these in code.** Where work must proceed first, use the "Interim safe behaviour" and document it as unapproved.

Status as of 2026-10-04 (financial policies, [ADR-023](decisions/ADR-023-financial-and-contract-policies.md)): OQ-15, OQ-16 and OQ-17 are **still open**. IMC can now enter and approve their answers as versioned policies, but none is entered or approved, and nothing here records an answer. **OQ-47 to OQ-50** were added.

Earlier status, 2026-10-03 (readiness assessment, [ADR-018](decisions/ADR-018-digital-readiness-assessment.md)): **OQ-06** and **OQ-07** are answered for the readiness categories by the owner-supplied framework document; **OQ-08** and **OQ-12** are partly answered; **OQ-41** and **OQ-42** were added; OQ-32 lists two more items.

Earlier status (Phases 4–7). **OQ-01** (the workbook was in the repository all along), **OQ-03** (marketplace model) and **OQ-20** (provider fields) are answered. OQ-07's manual interim is owner-approved for Phase 5. OQ-36 to OQ-40 were added. OQ-34 is answered (API-only, [ADR-013](decisions/ADR-013-api-only.md)). OQ-35 was added in Phase 10. OQ-24 and OQ-28 are partly answered. OQ-18 to OQ-23 have interim decisions in place (Phase 3 decisions D1–D4, and the OQ-23 language interim). OQ-32 and OQ-33 were added in Phases 2 and 3, and OQ-34 in Phase 9. OQ-25 was extended for the audit log. Every other question is **Open**.

## Summary

| ID | Topic | Owner | Priority | Blocks |
| --- | --- | --- | --- | --- |
| [OQ-01](#oq-01) | **Answered:** the workbook was in `docs/` since the baseline commit; read in full in Phase 4 | Product | — | — |
| [OQ-02](#oq-02) | PDF vs .docx source equivalence | Product | High | P0 traceability sign-off |
| [OQ-03](#oq-03) | **Answered:** factory-initiated marketplace ([ADR-015](decisions/ADR-015-marketplace-requests.md)); workflow details in OQ-38 | Business | — | — |
| [OQ-04](#oq-04) | Company sizes in scope; size definitions | Business | Medium | P5 |
| [OQ-05](#oq-05) | Sector list completeness ("agricultural"?) | Business | Medium | P2 sector seeder |
| [OQ-06](#oq-06) | **Answered:** the readiness questionnaire, scale and formula come from the framework document ([ADR-018](decisions/ADR-018-digital-readiness-assessment.md)) | Business / IMC experts | — | — |
| [OQ-07](#oq-07) | **Answered for the readiness categories:** 10–17, 18–25, 26–33, 34–40 ([ADR-018](decisions/ADR-018-digital-readiness-assessment.md)); the DOC maturity tiers keep no thresholds (see OQ-41) | Business / IMC experts | — | — |
| [OQ-08](#oq-08) | **Partly answered:** factories self-assess; expert validation still open | Business | Medium | Expert review of assessments |
| [OQ-09](#oq-09) | Enforce "foundation before digital"? Manual override? | Business | High | P5, P6 |
| [OQ-10](#oq-10) | Infrastructure & cyber audit data to capture | IMC experts | Medium | P5 |
| [OQ-11](#oq-11) | Baseline indicators, units, 6/12-month measurement | Business / IMC experts | High | P5, P8 |
| [OQ-12](#oq-12) | Roadmap structure, author, approval (**partly answered:** a focus, steps and services per readiness category) | Business | Medium | 2–3-year roadmaps |
| [OQ-13](#oq-13) | Provider evaluation scoring scale, pass mark, evaluators | Business | High | P4 |
| [OQ-14](#oq-14) | Per-level provider requirements: gates or inputs; evidence | Business | High | P4 |
| [OQ-15](#oq-15) | Revenue-share rule (conflicting figures) | Business / Finance / Legal | **Blocker** for P7 | P7 |
| [OQ-16](#oq-16) | Money flows, invoicing, currency, tax, gateway, cloud financing | Business / Finance | **Blocker** for P7 | P7 |
| [OQ-17](#oq-17) | Contract parties, templates, e-signature validity | Legal | **Blocker** for binding contracts | P6 |
| [OQ-18](#oq-18) | Registration model & required documents (**interim D1: admin-provisioned accounts**) | Business / Legal | Medium | Self-registration, document checks |
| [OQ-19](#oq-19) | Factory profile fields (**interim D4: name + sectors**) | Product | High | P5 |
| [OQ-20](#oq-20) | **Answered:** the workbook's provider fields ([ADR-014](decisions/ADR-014-catalog-and-provider-profiles.md)); requiredness in OQ-36 | Product | — | — |
| [OQ-21](#oq-21) | IMC internal roles & permission matrix (**interim D3: one imc_admin role**) | Business | Medium | Department roles |
| [OQ-22](#oq-22) | API clients (SPA, mobile, third party) (**interim D2: bearer tokens**) | Product / Tech | Medium | CORS origins; cookie mode |
| [OQ-23](#oq-23) | Languages (Arabic/English), bilingual data | Product | Medium | P1 error messages, P2 schema |
| [OQ-24](#oq-24) | **Partly answered:** engine = MySQL. Still open: version, Redis, hosting, data residency. | Tech / IMC IT | Medium | P10, P11 |
| [OQ-25](#oq-25) | Data protection & retention requirements, including the audit log | Legal | Medium | P2 deletion rules, P8 audit retention |
| [OQ-26](#oq-26) | Notification channels & events | Product | Low | P8 |
| [OQ-27](#oq-27) | Reports & KPI definitions; audiences | Business | Medium | P8 |
| [OQ-28](#oq-28) | **Partly answered:** git initialised. Still open: remote, branching/merge policy, CI. | Tech | Medium | CI |
| [OQ-29](#oq-29) | Platform name "Jahez" & branding use | Product | Low | P11 |
| [OQ-30](#oq-30) | Platform role in SaaS subscriptions | Business | Medium | P6, P7 |
| [OQ-31](#oq-31) | Training/qualification programmes in scope? | Business | Low | — |
| [OQ-33](#oq-33) | Security parameters: password policy, lockout thresholds, token lifetime | IMC security / IT | Medium | Production go-live |
| [OQ-32](#oq-32) | Proofread the seeded Arabic reference text against the original | Product (Arabic reader) | High | Any UI or API that shows the text |
| [OQ-34](#oq-34) | **Answered:** API-only ([ADR-013](decisions/ADR-013-api-only.md)) | Product / Tech | — | — |
| [OQ-35](#oq-35) | Performance targets and usage profile (users, request rates, latency, data volumes) | Business / IMC IT | Medium | Phase 10 measurements and thresholds |
| [OQ-36](#oq-36) | Which provider profile fields are required? | Product | Medium | Mandatory fields |
| [OQ-37](#oq-37) | May factories see a provider's contact person, email and phone? | Product / Legal | Medium | Directory contact details |
| [OQ-38](#oq-38) | Confirm the proposed request and negotiation states | Business | High | Phase 6 workflow sign-off |
| [OQ-39](#oq-39) | How much may IMC administrators see of requests and negotiations? | Business / Legal | Medium | IMC oversight |
| [OQ-40](#oq-40) | What happens to a provider's open requests when IMC suspends it? | Business | Medium | Suspension policy |
| [OQ-41](#oq-41) | Do the readiness categories correspond to the DOC maturity tiers and pathways? | Business | Medium | Showing a pathway with a readiness result |
| [OQ-42](#oq-42) | Recommended services the catalog does not offer (HR, production services, infrastructure assessment, CRM) | Product | Medium | Complete recommendations |
| [OQ-46](#oq-46) | What may an unapproved factory do, and what does a factory suspension pause? | Business | Medium | Factory approval policy |
| [OQ-47](#oq-47) | Which financial policy applies when several scopes match (provider, service, sector, global)? | Business / Finance | High | Scoped policies |
| [OQ-48](#oq-48) | Which date decides the revenue share of an invoice: agreement conclusion or invoice issue? | Finance | High | Revenue share on invoices |
| [OQ-49](#oq-49) | Who may prepare, approve and record payments under the financial policies? | Business / IMC | High | Granting `financial_policies.*`, `payments.record` |
| [OQ-50](#oq-50) | Retroactive amendments, credit notes and voiding of issued invoices | Finance / Legal | High | Correcting issued invoices and approved policies |

## Details

<a id="oq-01"></a>
### OQ-01: Services workbook not supplied

- **Question:** Please supply `Copy of الخدمات التحول الرقمي.xlsx`.
- **Evidence:** It was not attached in the Phase 0 session or found in the repository. The master prompt names it as the authoritative source for the seven service categories, all sub-services and (apparently) the provider profile fields.
- **Impact:** The catalog cannot be seeded or modelled with confidence. The provider/factory profile fields cannot be traced to a source.
- **Interim safe behaviour:** No catalog tables or seeders until the workbook is read. The seven category names in the master prompt are recorded as **unverified** in [requirements-traceability.md §5](requirements-traceability.md#5-service-catalog).
- **Answered 2026-10-03, with a correction.** The workbook has been in the repository since the baseline commit `020f811`: it is `docs/Copy of الخدمات التحول الرقمي.xlsx`, copied in at 19:59 and committed at 20:22.
  - The Phase 0 search ran before it existed, so "not found" was true then.
  - **Every later phase repeated "not supplied" without checking again.** That was a miss, even though the file sat in `docs/`.
  - It was read in full in Phase 4: one sheet, 8 provider fields, 7 categories and 43 service rows.
  - The catalog is seeded from it, and a test compares every row with the file ([ADR-014](decisions/ADR-014-catalog-and-provider-profiles.md)).
  - It contains no factory fields ([OQ-19](#oq-19) stays open).

<a id="oq-02"></a>
### OQ-02: PDF vs .docx source equivalence

- **Question:** Is `التحول الصناعي الذكي.pdf` (10 pages, supplied in Phase 0) the current, complete version of the `.docx` the master prompt names?
- **Interim:** The PDF is treated as the authoritative DOC source.

<a id="oq-03"></a>
### OQ-03: Operating model: marketplace or IMC-mediated assignment?

- **Question:** Do factories post service requests that providers answer with offers and negotiate (as the master prompt assumes)? Or does IMC select and assign providers using the evaluation matrix (as DOC §7 step 4 describes)? Or a mix of both, for example IMC shortlists and the factory chooses?
- **Evidence:** DOC never mentions requests, offers or negotiation. It says "اختيار مقدمي الخدمات عبر مصفوفة التقييم" (selecting providers via the evaluation matrix).
- **Impact:** This defines the entire Phase 6 data model and state machine, plus authorization (who can see which factory's needs).
- **Interim:** Phase 6 design is not started. Phases 1–5 do not depend on it.
- **Answered 2026-10-03 by the owner:** Jahez is a **factory-initiated B2B marketplace**.
  - The factory sees eligible providers and sends a request to one or several of them.
  - Each provider accepts or declines independently.
  - Accepting opens a private negotiation with that provider.
  - Accepting a request is not an offer, a contract, an invoice or a payment.
  - Admin moderation must not remove the factory's choice.

  Implemented in Phase 6 ([ADR-015](decisions/ADR-015-marketplace-requests.md)). The proposed states await confirmation ([OQ-38](#oq-38)). The DOC's evaluation matrix is now read as IMC's approval of providers before they become visible ([ADR-014](decisions/ADR-014-catalog-and-provider-profiles.md)).

<a id="oq-04"></a>
### OQ-04: Company sizes in scope

- **Question:** Are large companies in scope (p.2) or only small and medium ones (p.1)? How is size defined (employees, revenue, investment)? Does Egypt's official MSME classification apply?
- **Interim:** If a size field is needed before an answer, store it as a nullable value from a configurable list. Never derive it.
- **Applied (2026-10-03):** `factories.size`, nullable, validated against `config/jahez.php` `factories.sizes` (default: small, medium, large, as DOC p.2 lists them). Set by IMC administrators only. **Decision needed:** whether `large` stays in scope, and how size is defined.

<a id="oq-05"></a>
### OQ-05: Sector list completeness

- **Question:** Are the four DOC sectors the complete list? Is "agricultural" (p.4) a fifth sector? Are sub-sectors or an "other" option needed? Can a factory belong to more than one sector?
- **Interim:** Seed only the four listed sectors, as reference data editable by an admin.

<a id="oq-06"></a>
### OQ-06: Readiness index definition

- **Question:** What are the questions, answer scales, dimension weights and aggregation formula of مؤشر الجاهزية الرقمية والصناعية? DOC names only the areas: infrastructure, operations, leadership, existing systems. Does it relate to an external framework (for example SIRI) or an IMC framework?
- **Interim:** No official score is calculated. Phase 5 may store raw answers against a **versioned, unapproved** methodology record, with no tier assignment.
- **Answered 2026-10-03 by the owner:** the framework document «إطار تقييم مستوى الجاهزية الرقمية» (now `docs/إطار تقييم مستوى الجاهزية الرقمية.docx`) defines the index:
  - five pillars with two questions each;
  - four choices per question, worth 1–4 points;
  - the total is the plain sum (10–40), with no weights.

  It is implemented as questionnaire version 1 ([ADR-018](decisions/ADR-018-digital-readiness-assessment.md)). Whether it relates to an external framework such as SIRI is not stated.

<a id="oq-07"></a>
### OQ-07: Tier thresholds

- **Question:** Which numeric score range maps to each tier? DOC gives only qualitative bands: ضعيف/منخفض, متوسط الأدنى, متوسط الأعلى, متقدم/مرتفع.
- **Interim:** An IMC user assigns the tier manually and must record a justification (audit-logged). Automatic mapping stays disabled.
- **2026-10-03:** the owner approved this manual classification as the Phase 5 scope ([ADR-016](decisions/ADR-016-manual-factory-classification.md)). The question itself (thresholds for a computed score) stays open.
- **Answered 2026-10-03 for the readiness categories:** B4 Automation 10–17, Basic 18–25, Advanced 26–33, Smart 34–40 (framework document §3). The server classifies every assessment automatically; manual classification is retired ([ADR-018](decisions/ADR-018-digital-readiness-assessment.md)). The DOC maturity tiers above keep no numeric thresholds, because the new document does not say it applies to them ([OQ-41](#oq-41)).

<a id="oq-08"></a>
### OQ-08: Who performs and enters the assessment?

- **Question:** Certified experts doing a field visit (DOC §3 "التقييم الميداني", §7), a factory self-assessment, or both (self-assessment followed by expert validation)? Are "certified assessment experts" IMC staff or accredited externals?
- **Partly answered 2026-10-03 (owner):** the readiness assessment is a **self-assessment by the factory's own members**. IMC administrators read every result and submit none ([ADR-018](decisions/ADR-018-digital-readiness-assessment.md)). **Still open:**
  - whether experts validate or override a self-assessment, and how;
  - whether a field visit adds data.

<a id="oq-09"></a>
### OQ-09: Path gating

- **Question:** Must the platform stop a factory from entering Path 2 (digital) until it completes Path 1 (foundation)? DOC step 3 says foundation applies "if any" (إن وجد). Can IMC override a classification or path?

<a id="oq-10"></a>
### OQ-10: Infrastructure & cyber readiness audit data

- **Question:** What structured data should the audit capture (checklists, findings, severity, evidence files)? Are attachments needed? Uploads bring extra security controls.

<a id="oq-11"></a>
### OQ-11: Baseline and impact indicators

- **Question:** Which operational indicators make up the baseline (for example OEE, waste %, labour efficiency, energy consumption)? What are their units and measurement methods? Who enters the 6- and 12-month measurements, and who verifies them?

<a id="oq-12"></a>
### OQ-12: Roadmap structure

- **Question:** What does a 2–3-year roadmap contain (phases, services or levels, timelines, owners, estimated cost)? Who writes it, who approves it, and can the factory see or comment on it?
- **Partly answered 2026-10-03:** the framework document's §5 «خارطة الطريق للتوصيات» gives each readiness category a focus, steps and recommended services. The platform shows these with every result ([ADR-018](decisions/ADR-018-digital-readiness-assessment.md)). Still open: a per-factory 2–3-year plan with timelines, owners and approval.

<a id="oq-13"></a>
### OQ-13: Provider evaluation mechanics

- **Question:** What is the scoring scale per criterion (0–5? 0–100?), the minimum passing total, and any per-criterion minimums? Who evaluates? How often are providers re-evaluated? Which documents are evidence? What happens to a provider that fails (rejection, conditional approval, appeal)?
- **Interim:** Weights may be stored as DOC states them (30/25/20/15/10), with a methodology version. Pass/fail is not computed.
- **Applied (2026-10-03):** IMC records evaluations with a written assessment per criterion (`/service-providers/{id}/evaluations`). Scores, the weighted total and the pass-mark comparison exist only once the owner sets `JAHEZ_PROVIDER_EVALUATION_SCALE_MAX` and `JAHEZ_PROVIDER_EVALUATION_PASS_MARK`; until then scores are refused. The result never approves or rejects a provider. **Decisions needed:** the scale, the pass mark, any per-criterion minimum, who may read an evaluation (now IMC only), and how often to re-evaluate.

<a id="oq-14"></a>
### OQ-14: Per-level provider requirements

- **Question:** Are the DOC §3 requirements per level (for example "≥ 5 years ERP/HRMS" for Basic DX, "ISO/IEC 62443" for Smart DX) hard eligibility gates for offering services at that level? Or are they inputs to the evaluation? What proof is required?

<a id="oq-15"></a>
### OQ-15: Revenue-share rule

- **Question:** What is the approved revenue-share rule? DOC §5 gives an *initial* IMC share of 20–30% and recommends a variable share by project size, service nature and operating cost. DOC §6 has providers accept 5–20%. Which applies? At what level is it set (per contract, per service, per provider)? Who approves it?
- **Interim:** **No percentage may be hard-coded or defaulted.** Phase 7 does not start until this is answered.
- **Update (2026-10-04, [ADR-023](decisions/ADR-023-financial-and-contract-policies.md)):** the setting below is replaced by a `revenue_share` policy managed in the database. It can be set per service, sector or provider, with effective dates, and needs a second administrator's approval. The only method supported is a percentage of the subtotal before tax, informational only, with no payout. **No rate is entered or approved**; the answer is still needed, together with OQ-47 and OQ-48.
- **Boundary built (2026-10-03, [ADR-017](decisions/ADR-017-agreements-contracts-billing.md)):** `JAHEZ_REVENUE_SHARE_PERCENT`, unset (no longer read since ADR-023). When the owner sets it, issued invoices show IMC's share of the subtotal, informational only; nothing is paid out. **Decisions needed:** the rate or rule (fixed, or variable by project size, service type and cost as DOC §5 recommends — a variable rule needs more than this one setting), its base (the subtotal before tax is the assumption of the current setting), and payouts.

<a id="oq-16"></a>
### OQ-16: Money flows

- **Question:** Does the platform issue invoices or collect payments at all? Who pays whom (factory → IMC → provider, or factory → provider with IMC commission)? Which currency (EGP assumed, not confirmed)? What VAT/tax treatment applies? Which payment gateway? What is the refund policy? How does the "flexible cloud financing mechanism" (DOC §1) work?
- **Partly answered (2026-10-03):** offer prices in the marketplace are EGP only and informational (owner decision). The billing currency and everything else here remain open.
- **Update (2026-10-04, [ADR-023](decisions/ADR-023-financial-and-contract-policies.md)):** the issuer, payer, numbering, invoice types, taxes and fees (including whether prices include tax), payment terms (due days, part payments) and manual payment entries are now database policies.
  - They are versioned, effective-dated and approved by a second administrator.
  - Invoices store the versions and the full calculation they were issued under.
  - **None is entered or approved.** The decisions below are still needed, and each operation stays 409 `policy_not_configured` until its policy is approved.
  - Supported options are deliberately narrow: payer = the factory, currency = EGP, one invoice type (agreed service), due date = days after issue. Anything else needs a decision and a code change.
  - Still not built: e-invoicing with the Egyptian Tax Authority, withholding, credit notes (OQ-50), refunds, payouts, billing schedules, any payment gateway.
- **Boundary built (2026-10-03, [ADR-017](decisions/ADR-017-agreements-contracts-billing.md)):** invoices and payments exist as records, and every money operation waits for its setting, unset by default: `JAHEZ_INVOICE_ISSUER`, `JAHEZ_INVOICE_NUMBER_PREFIX`, `JAHEZ_TAX_RATE_PERCENT`, `JAHEZ_PAYMENT_GATEWAY` (no gateway adapter ships). Until then drafting, issuing and paying answer 409 `policy_not_configured`. **Decisions needed:** who issues invoices (IMC or the provider) and to whom; the numbering format and whether invoices go through the Egyptian Tax Authority's e-invoicing; VAT and withholding; the gateway (and its credentials); billing schedules (one invoice per agreement now); credit notes and voiding; refunds; payouts; the billing currency (EGP for offers).

<a id="oq-17"></a>
### OQ-17: Contracts

- **Question:** Who are the parties (IMC, factory, provider: three-party)? Are there templates? Is electronic signature required and legally valid for these contracts, and which e-signature provider applies? How is the knowledge-transfer legal memo (DOC §6) attached?
- **Interim:** Any contract record built before an answer is labelled a **draft, not legally binding** in the API and the docs.
- **Update (2026-10-04, [ADR-023](decisions/ADR-023-financial-and-contract-policies.md)):** IMC can manage versioned contract templates (title, parties including whether IMC is one, duration, knowledge-transfer minimum of at least two, clauses).
  - A contract draft stores a snapshot of the agreed terms and of the template version.
  - **Drafts remain `draft_not_binding`**: no signature, approval, activation, expiry, renewal or termination state exists, and a template does not make a contract legally valid.
  - The parties, the template content, e-signature and its legal validity are still undecided.
- **Applied (2026-10-03, [ADR-017](decisions/ADR-017-agreements-contracts-billing.md)):** accepting an offer records an immutable agreement. Either party may draft a contract for it, with the DOC §6 knowledge-transfer commitment. Drafts are versioned, cancellable and always `draft_not_binding`; there is no signature or approval state. **Decisions needed:** the parties (is IMC a party?), the templates, e-signature and its legal validity, how the knowledge-transfer memo is attached, and who may draft.

<a id="oq-18"></a>
### OQ-18: Registration model

- **Question:** Can factories and providers self-register, or does IMC invite or create accounts? Is admin approval required before an account becomes active? Which identity or company documents are required (for example commercial registry, tax card)? Can one organization have several users, and does an organization need an owner user?
- **Interim:** Do not invent document requirements.
- **Interim decision D1 (Phase 3, [ADR-011](decisions/ADR-011-account-provisioning-and-credentials.md)):** no public self-registration. IMC administrators create accounts, and invitees set their password through an emailed link. The first administrator is created with `php artisan app:create-admin`. No documents are collected.
- **Partly answered (2026-10-03, owner brief, [ADR-019](decisions/ADR-019-self-registration-documents-and-legal-changes.md)):** factories and providers self-register; providers need IMC approval before factories see them; logo, commercial and tax registration documents may be uploaded. **Still open:** which documents (if any) are **required**; whether factories need IMC approval before using the marketplace (none is applied now); whether one organization may have several users (self-registration creates one).

<a id="oq-19"></a>
### OQ-19: Factory profile fields

- **Question:** Which fields make up a factory profile? DOC implies only size and sector. Fields may be in WB ([OQ-01](#oq-01)).
- **Interim decision D4 (Phase 3):** factories store `name` and sectors only.
- **Partly answered (2026-10-03, [ADR-019](decisions/ADR-019-self-registration-documents-and-legal-changes.md)):** the owner named legal name, contact person and details, address, registration numbers and logo; all are stored and optional. **Decision needed:** which are required. Configurable as the onboarding checklist: `JAHEZ_FACTORY_REQUIRED_FIELDS` (default `sectors`).

<a id="oq-20"></a>
### OQ-20: Provider profile fields

- **Question:** Confirm the fields the master prompt lists (company name, representative/contact name, job title, email, phone, website, years of DX experience, target sectors) against WB. Is a provider's specialisation tied to catalog categories, to DX levels, or to both?
- **Interim decision D4 (Phase 3):** service providers store `name` and target sectors only.
- **Answered 2026-10-03 from the workbook:**
  - The fields are company name, representative / company representative, job title, email, phone, website, years of experience in digital transformation, and target industrial sectors.
  - Specialisation is tied to **catalog services**: the form's tick boxes, under the 7 categories.
  - None is marked as required ([OQ-36](#oq-36)).

  Implemented in Phase 4 ([ADR-014](decisions/ADR-014-catalog-and-provider-profiles.md)). Whether DX levels also apply is part of [OQ-14](#oq-14).

<a id="oq-21"></a>
### OQ-21: IMC internal roles

- **Question:** What permissions should each IMC unit in DOC §7 have: Digital Transformation Dept, Competitiveness & Productivity Dept, Executive Management, certified assessment experts? Is a single "platform admin" role acceptable for the MVP? Who can approve providers, assign tiers and see financial data?
- **Interim decision D3 (Phase 3, [ADR-006](decisions/ADR-006-roles-and-permissions.md)):** one `imc_admin` role holding named permissions. Policies check permissions, not roles, so departments can be split out later without changing them.

<a id="oq-22"></a>
### OQ-22: API clients

- **Question:** Which clients will consume the API: a first-party web SPA (same top-level domain?), mobile apps, third-party integrations? This decides between Sanctum SPA cookie authentication and bearer tokens, and drives the CORS/CSRF configuration and token lifetime.
- **Interim decision D2 (Phase 3, [ADR-003](decisions/ADR-003-authentication-bearer-tokens.md)):** Sanctum bearer tokens only (cookie auth disabled), 8-hour lifetime. CORS origins stay empty until the clients are known.

<a id="oq-23"></a>
### OQ-23: Languages

- **Question:** Is the platform Arabic-only, English-only or bilingual? Do reference data and catalog items need both `name_ar` and `name_en`? Which language should API validation and error messages use (Accept-Language negotiation)?
- **Interim applied in Phase 2 ([ADR-010](decisions/ADR-010-reference-data.md)):** reference tables have authoritative `*_ar` columns and nullable `*_en` columns. `*_en` is filled only where DOC itself prints English (for example `Basic DX`, `Foundation Tier`). Sector, pathway and criterion English names are NULL. No translations were invented.

<a id="oq-24"></a>
### OQ-24: Target infrastructure

- **Answered 2026-10-02 (engine):** "use mysql". The app and tests now use the MySQL driver ([ADR-004](decisions/ADR-004-database-mysql.md)).
- **Phase 10 (2026-10-03):** MySQL 8.4.9 runs locally on port 3307, and the whole suite passes on it as well as on MariaDB 10.4 ([testing strategy](testing/strategy.md#2-commands)).
- **Still open:** Which production MySQL version (recommendation: 8.4 LTS)? Note that the local development server is MariaDB 10.4.32, not MySQL. Is Redis available? Where will it be hosted (IMC data centre, Egyptian cloud, Laravel Cloud)? Are there data-residency requirements?

<a id="oq-25"></a>
### OQ-25: Data protection and retention

- **Question:** Which data-protection obligations apply (for example Egypt's personal data protection law)? What retention periods apply to accounts, assessments, contracts, financial records and audit logs? Should records be soft-deleted, archived or hard-deleted?
- **Audit log (added in Phase 9):**
  - How long are audit entries kept, and how are expired ones purged or archived?
  - Failed and throttled logins store the email exactly as typed, even when no account exists. Those addresses may belong to people outside the platform. May they be kept, and for how long?
  - Should the production database user lose UPDATE and DELETE on `audit_logs`, so that entries are protected in the database and not only in the application ([ADR-012](decisions/ADR-012-audit-log.md))? Purging would then need a separate privileged job.
- **Interim:** audit entries are kept indefinitely. They are append-only in the application, and nothing deletes them.

<a id="oq-26"></a>
### OQ-26: Notifications

- **Question:** Which events notify whom, and through which channels (email, SMS, in-app)? Which email/SMS providers?

<a id="oq-27"></a>
### OQ-27: Reports and KPIs

- **Question:** Which reports are required, and for which audience (IMC management, factory, provider)? How are DOC §8 KPIs calculated, and from which data? Are the OEE targets (15–25% programme-wide vs "up to 50%" for the Advanced tier) displayed as targets or computed from measurements?

<a id="oq-28"></a>
### OQ-28: Version control

- **Answered 2026-10-02 (init):** git initialised on `main` with baseline commit `020f811`. Phase work happens on `phase/NN-…` branches ([ADR-001](decisions/ADR-001-version-control.md)).
- **Still open:** Is there a remote (GitHub/GitLab)? What is the merge/review policy? CI depends on the answer.

<a id="oq-29"></a>
### OQ-29: Naming and branding

- **Question:** DOC never uses the platform name "Jahez" (جاهز). Confirm the product name and whether IMC and Ministry of Industry branding may appear in API metadata or documentation.

<a id="oq-30"></a>
### OQ-30: SaaS subscription role

- **Question:** Does the platform only broker services, or also track and provision SaaS subscriptions (ERP/HRMS/CRM/MES) that providers deliver under the "Three Zeros" model?

<a id="oq-31"></a>
### OQ-31: Training programmes

- **Question:** DOC §1 mentions training and qualification programmes and capacity building for IMC engineers. Are these in the platform's scope (enrolment, tracking), or are they handled outside it?

<a id="oq-32"></a>
### OQ-32: Proofread the seeded Arabic reference text

- **Question:** Can an Arabic-reading owner check the seeded text against the original document (ideally the `.docx`, [OQ-02](#oq-02))? The PDF's text layer is garbled by right-to-left extraction, so all Arabic in `database/seeders/{Sector,Pathway,MaturityTier,EvaluationCriterion}Seeder.php` was transcribed from the page images.
- **What to check:** wording; the placement of parentheses where Arabic and Latin text mix (for example the Foundation tier's approved-path cell, p.6, whose parentheses are unbalanced in the PDF rendering); and any obvious typos in the source that should, or should not, be kept (for example "الي" vs "إلى" on p.3, "رقمنه" on p.4).
- **How to apply corrections:** edit the seeder arrays and run `php artisan db:seed --class=ReferenceDataSeeder --force`. The seeders update rows in place.
- **Added 2026-10-03 (readiness framework document, `ReadinessAssessmentSeeder`):** the text was read from the .docx, not transcribed, and a test compares it with the file. Two source anomalies are kept verbatim until the owner decides:
  - the B4 lines «نظم تخطيط وإدارة موارد المؤسسات (ERP).)» and «إدارة سير العمل والإجراءات (Workflow Management).)» end with a stray «)»;
  - the Basic list prints «بناء القدرات والتوعية والتدريب في مجال الأمن السيبراني.» twice (rows 7 and 9). It is seeded once.

<a id="oq-33"></a>
### OQ-33: Security parameters

- **Question:** Can IMC security/IT confirm or adjust the provisional security values ([ADR-011](decisions/ADR-011-account-provisioning-and-credentials.md))?
  - Password policy: at least 12 characters, at most 72 bytes, no composition rules, no breached-password check.
  - Login lockout: 5 failures per minute per email+IP, and 20 per 15 minutes per account. The latter lets an attacker lock a known account out for 15 minutes; this trade-off is accepted until confirmed.
  - Token lifetime: 8 hours, with no refresh tokens.
  - Reset and invitation link lifetime: 60 minutes.
  - Is multi-factor authentication required for IMC administrators?
- **Interim:** the values above are in force and documented as provisional.

<a id="oq-34"></a>
### OQ-34: API-only project?

- **Question:** Is this repository only the JSON API, with the client applications elsewhere? If so, the Laravel welcome page (`GET /`), `resources/views/welcome.blade.php` and the Vite/Tailwind scaffolding can be removed.
- **Why it matters:** the welcome page runs through the web middleware, which starts a session. With `SESSION_DRIVER=database`, every visitor without a cookie adds a row to `sessions` (finding FC-09). It also reveals the framework.
- **Answered 2026-10-03:** yes, API-only ("Yes, remove the welcome page"). Web routes, the welcome page, the Vite/Tailwind tooling and Sanctum's CSRF-cookie route were removed ([ADR-013](decisions/ADR-013-api-only.md)).

<a id="oq-35"></a>
### OQ-35: Performance targets and usage profile

- **Question:** What load must the platform carry, and how fast must it answer?
  - Expected numbers of IMC staff, factory and provider users, and their peak concurrent use.
  - Peak requests per minute, and the mix of reads and writes.
  - Acceptable p95/p99 response times and error rate.
  - Data volumes after one and three years (factories, providers, users, audit entries).
- **Why it matters:** Phase 10 cannot set thresholds or a realistic load mix without these numbers, and inventing them is not allowed. The scripts in `tests/performance/` use a provisional technical mix and correctness-only thresholds ([performance plan](performance/plan.md)).
- **Interim:** no performance targets; no capacity claims.

<a id="oq-36"></a>
### OQ-36: Required provider fields

- **Question:** The workbook's provider form marks no field as required. Which of company name, representative, job title, email, phone, website, years of experience, target sectors and services must a provider fill in? Must any be verified (for example the email)?
- **Interim:** only the company name is required, as in Phase 3.
- **Configurable (2026-10-03):** `JAHEZ_PROVIDER_REQUIRED_FIELDS` (comma-separated, from representative_name, job_title, email, phone, website, dx_experience_years, sectors, services). Approval and a provider's review request are refused (422) until those fields are filled. Empty by default. **Decision needed:** the list.

<a id="oq-37"></a>
### OQ-37: Contact details in the provider directory

- **Question:** May a factory see a provider's representative name, job title, email and phone in the directory? Or only after the provider accepts its request? Or never, because all contact goes through the platform?
- **Interim (PROPOSED):** the directory shows company name, website, experience, sectors and services only. Contact happens through the negotiation messages.
- **Configurable (2026-10-03):** `JAHEZ_DIRECTORY_SHOWS_CONTACT_DETAILS=true` adds the four contact fields to the directory list and profile. Off by default. **Decision needed:** on or off, or shown only after the provider accepts a request (not built).

<a id="oq-38"></a>
### OQ-38: Request and negotiation states

- **Question:** Please confirm the PROPOSED workflow in [workflows.md](workflows.md#2-marketplace-request-model-owner-approved-states-proposed-oq-38-adr-015). In particular:
  - Does the first accepted offer award the whole request (current behaviour), or may a factory agree with several providers?
  - May a provider withdraw an offer?
  - Should requests or offers expire?
  - May a factory add providers to an open request, or reopen a cancelled one?
  - Should a request close automatically once every provider has declined or been withdrawn? It stays `open` now; the factory can add providers (PROPOSED) or cancel it.
- **Applied as configurable or provisional (2026-10-03):** the single-award rule is a setting (`JAHEZ_MARKETPLACE_SINGLE_AWARD`, default on: the first acceptance closes the other threads). Adding providers to an open request is PROPOSED. Offers may carry a validity date the provider sets; the platform sets none. Offer withdrawal and request expiry are not built.
  - Is 20 providers per request a sensible upper bound?
- **Interim:** the PROPOSED states are enforced as documented.

<a id="oq-39"></a>
### OQ-39: IMC oversight of negotiations

- **Question:** DOC §7 gives IMC a supervisory role. Should IMC administrators read negotiation messages and offer terms (for dispute handling, for example), or only see that requests exist and their statuses? Is consent from the factory and provider needed?
- **Interim (PROPOSED):** IMC sees requests and thread statuses, never messages or offers. The audit log records statuses and offer versions, never prices or terms.

<a id="oq-40"></a>
### OQ-40: Suspended providers and their open requests

- **Question:** When IMC suspends a provider, what happens to the requests already sent to it and to its running negotiations? Should they be paused, closed, or allowed to finish? Should the factories be told, and may they still accept an offer the provider made before the suspension?
- **Interim (PROPOSED, safest reversible option):** the threads are paused, not closed. While the provider is not approved, it cannot accept requests, nobody can post messages or offers, and the factory cannot accept its offers (409). Either side can still leave: the provider declines, the factory withdraws or cancels. When IMC approves the provider again, the threads resume. Factories are not notified (notifications: [OQ-26](#oq-26)).

<a id="oq-41"></a>
### OQ-41: Readiness categories and the DOC maturity tiers

- **Question:** Do the readiness framework's four categories correspond to the concept paper's four maturity tiers and their approved pathways? The candidate pairs are:
  - B4 Automation ↔ التأسيسي (Foundation);
  - Basic ↔ الرقمي الأساسي (Basic DX);
  - Advanced ↔ المصنع المتقدم (Advanced DX);
  - Smart ↔ المصنع الذكي (Smart DX).

  If they do correspond, should a readiness result also show the tier's approved pathway (المسار التنفيذي المعتمد)?
- **Evidence:** both sets have four ordered levels with similar descriptions, but neither document states a correspondence. The DOC tiers have qualitative bands only.
- **Interim (owner decision 2026-10-03):** the two sets are kept **separate** ([ADR-018](decisions/ADR-018-digital-readiness-assessment.md)). `maturity_tiers` is unchanged, its score columns stay NULL, and a readiness result names no tier or pathway.

<a id="oq-42"></a>
### OQ-42: Recommended services the catalog does not offer

- **Question:** The readiness roadmap (§5) recommends some services that the services workbook's catalog does not list. Should they be added to the catalog (which needs the workbook updated, ADR-014), mapped to existing services, or kept as text only?
  - B4 Automation: «الموارد البشرية» (human resources), «خدمات الإنتاج ( 5s- lean – الجودة )» (production services: 5S, Lean, quality) and «تقييم البنية التحتية» (infrastructure assessment).
  - Basic: «تقييم البنية التحتية».
  - The B4 steps text also names a CRM system; the catalog has no CRM service.
- **Interim:** the lines are shown in the roadmap with no catalog service. No provider is offered for them, and no catalog service was created or linked by guess ([ADR-018](decisions/ADR-018-digital-readiness-assessment.md)). Lean, 5S and process re-engineering appear in the DOC foundational pathway, but not as catalog services.

<a id="oq-43"></a>
### OQ-43: IMC approval of agreements and what follows a rejection

- **Context (2026-10-04, [ADR-020](decisions/ADR-020-provider-and-factory-portals.md)):** the owner's Phase 2 brief requires ministry (IMC) approval before an agreed proposal becomes an approved subscription or contract. IMC reviewers now approve or reject each agreement; a contract draft or invoice needs the approval (`JAHEZ_AGREEMENTS_IMC_APPROVAL_REQUIRED`, default on). Reviewers see the agreed terms and price, not the negotiation.
- **Questions:** Is IMC a party to the contract (three-party) or only an approver? After a rejection, does the request reopen, may the factory accept another provider's offer, or is the request closed? Is there a review deadline? Should approval criteria be recorded?
- **Interim:** a rejection is final for that agreement; the request stays awarded and nothing else changes. No contract or invoice is possible for a rejected agreement.

<a id="oq-44"></a>
### OQ-44: Promotions (إعلان) of provider listings

- **Context (2026-10-04, ADR-020):** IMC administrators place promotions on provider listings; they sort first and are labelled «إعلان» in the factory portal, and never change eligibility.
- **Questions:** Are promotions paid (and invoiced), and by whom? Is «إعلان» or «إعلان ممول» the required label? Are there limits per provider, category or period? Do promotions appear on the public landing page (whose ads are still browser-local mock data)?
- **Interim:** no fee, invoice or payment is attached to a promotion; label «إعلان»; no limits.
- **Update (2026-10-04, [ADR-022](decisions/ADR-022-announcements-and-listing-resubmission.md)):** the landing page now shows announcements IMC publishes through the API (no longer browser-local mock data). They are separate from promotions and name no provider. Still open: whether announcements or promotions are paid, need a second approver, or have limits.

<a id="oq-45"></a>
### OQ-45: Factory legal information review

- **Context (2026-10-04, ADR-020):** factories have no IMC approval step (OQ-18), yet the brief asks that verified legal information change only after review.
- **Question:** When does a factory's legal information count as verified, and who verifies it?
- **Interim (PROPOSED):** a value counts as recorded once set; members fill empty values directly and change recorded ones through a reviewed change request (`JAHEZ_FACTORY_LEGAL_CHANGES_REVIEWED`, default on).
- **Update (2026-10-04, [ADR-021](decisions/ADR-021-ministry-administration.md)):** factories now have an IMC approval step (OQ-46). Whether legal values should count as verified only once a factory is approved, as for providers, is still to confirm; the interim above is unchanged.

<a id="oq-46"></a>
### OQ-46: What an unapproved factory may do

- **Context (2026-10-04, ADR-021):** the Phase 3 brief adds IMC approval of factory accounts (approve, reject, request corrections, suspend), separate from the readiness classification.
- **Questions:** Which actions need an approved factory: sending requests only, or also browsing listings, the assessment, negotiation on requests already open? Does a suspension pause the factory's open requests and threads? Which documents and fields are required before approval (with OQ-18, OQ-19)? Should IMC approve factories it creates itself?
- **Interim (PROPOSED):** only sending service requests and adding providers need approval (`JAHEZ_FACTORY_APPROVAL_REQUIRED`, default on). Self-registered factories start pending; IMC-created and pre-existing factories are approved. A suspension does not touch open requests. Approval needs the configured onboarding fields (default: sectors).

<a id="oq-47"></a>
### OQ-47: Precedence of financial policy scopes

- **Context (2026-10-04, [ADR-023](decisions/ADR-023-financial-and-contract-policies.md)):** a policy can be set globally, for a sector, a catalog service or a service provider.
- **Question:** When several apply to the same agreement, which wins? Is "most specific wins" right? What applies when a factory has two sectors that each have a policy?
- **Interim (PROPOSED):** provider, then service, then sector, then global. Two sector policies that both apply are refused (409, `decision_needed: OQ-47`) rather than one being chosen.

<a id="oq-48"></a>
### OQ-48: Which date decides the revenue share of an invoice

- **Context (ADR-023):** an agreement records the revenue-share version in effect when the offer was accepted, for reference. An invoice resolves every policy, the revenue share included, on its issue day.
- **Question:** Should an invoice use the share in force when the agreement was concluded, or when the invoice is issued?
- **Interim (PROPOSED):** the issue day, for every policy. Both versions are visible: the agreement's and the invoice's.

<a id="oq-49"></a>
### OQ-49: Who prepares, approves and records payments

- **Context (ADR-023):** `financial_policies.manage`, `financial_policies.approve` and `payments.record` belong to no role. They are granted to named IMC administrators with `php artisan jahez:permissions`, and the approver of a version must differ from whoever prepared it.
- **Question:** Which IMC units or people hold each permission (see OQ-21)? Do high-impact policies need more than one approver? Who may record manual payments, and must it differ from the issuer?
- **Interim:** nobody holds them on a fresh installation; the local demo seeder grants them to two demo accounts in the local environment only.

<a id="oq-50"></a>
### OQ-50: Retroactive amendments, credit notes and voiding

- **Context (ADR-023):** approved policy versions, issued invoices and contract drafts are never changed, and nothing is approved retroactively.
- **Question:** Is a retroactive policy amendment ever allowed? If so, who authorises it and how are records made under the old version treated? How are issued invoices corrected (credit notes, voiding) and refunds started?
- **Interim:** not built. An issued invoice cannot be cancelled (409 `policy_not_configured`); a wrong policy is corrected by a new version from today or later.

<a id="oq-51"></a>
### OQ-51: Correcting a stored readiness assessment

- **Context (ADR-018 addendum 2):** assessments and their answers are append-only; a factory's level changes only when its members submit a new assessment. The owner brief allows an exceptional correction only through an explicit, permission-controlled and audited workflow, and forbids changing a score or level to obtain an approval.
- **Question:** Is a correction ever allowed (for example an assessment submitted by mistake)? Who may make it, with what evidence, and does it void the assessment or require the factory to resubmit? Must the factory be told?
- **Interim (owner decision, 2026-10-04):** not built. No endpoint or permission changes a stored score, level or answer.
