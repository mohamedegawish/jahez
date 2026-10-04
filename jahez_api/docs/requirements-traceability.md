# Requirements Traceability Matrix

**Status:** readiness assessment added 2026-10-03 (source RDA, §3.4a, [ADR-018](decisions/ADR-018-digital-readiness-assessment.md)). Earlier: end of Phase 3 (2026-10-02). Phase 2 implemented the DOC reference data as seeded tables. Phase 3 implemented the actors (IMC administrator, factory and provider accounts) with authentication and tenant isolation (R-ACT-01/03/04, R-PLT-01). The rows show their endpoints and tests. Phase 1 delivered platform plumbing only.

## 1. Sources

| Key | File | Status |
| --- | --- | --- |
| **DOC** | `التحول الصناعي الذكي.pdf`: "Smart Industry – Ecosystem" initiative, Industrial Modernisation Centre (IMC), 10 pages | **Read in full** on 2026-10-02. It was supplied as a PDF, while the master prompt names a `.docx` with the same title. Equivalence is unconfirmed ([OQ-02](open-questions.md#oq-02)). |
| **WB** | `docs/Copy of الخدمات التحول الرقمي.xlsx`: digital-transformation services workbook (one sheet, A1:D61): a provider registration form with 8 provider fields and the 7-category service structure | **Read in full** on 2026-10-03 (Phase 4). It had been in the repository since the baseline commit, but was wrongly reported as missing until then ([OQ-01](open-questions.md#oq-01)). |
| **RDA** | `docs/إطار تقييم مستوى الجاهزية الرقمية.docx`: Digital Readiness Level Assessment framework. §1 pillars, §2 scoring, §3 categories, §4 questionnaire, §5 roadmap per category | **Read in full** on 2026-10-03 from the .docx XML (clean text, no transcription). Supplied by the owner; it supersedes the manual-classification interim ([ADR-018](decisions/ADR-018-digital-readiness-assessment.md)). `ReadinessAssessmentSourceTest` compares the seeded data with the file. |
| **OWNER** | Owner decisions recorded in this project (2026-10-03): the factory-initiated marketplace, approval before visibility, sector eligibility, EGP offer prices, manual classification (since superseded by RDA) and self-edits. Readiness assessment: factory self-assessment; readiness categories kept separate from the DOC tiers; one recommendation line mapped to both catalog services of the same name | Recorded in ADR-014 to ADR-018 and in [open-questions.md](open-questions.md). |

The `.docx` version of DOC named in the briefs is **not** in this environment; DOC was read from the PDF ([OQ-02](open-questions.md#oq-02)).

DOC section index (page numbers from the document footer):

| Ref | Section (exact source heading) | Pages |
| --- | --- | --- |
| DOC §1 | أولاً: الملخص التنفيذي والإطار العام (Executive Summary), including أهداف البرنامج and الشركات المستهدفة ومسارات التحول | 1–2 |
| DOC §2 | ثانياً: آلية التقييم والتشخيص (بوابة الدخول للمشروع) | 2 |
| DOC §3 | ثالثاً: مسارات تقديم الخدمة (The Transformation Pathways) | 3–5 |
| DOC §4 | رابعاً: مصفوفة تقييم وتصنيف العملاء (Client Assessment & Tiers Matrix) | 6 |
| DOC §5 | خامساً: النموذج التجاري ومشاركة العوائد | 7 |
| DOC §6 | سادساً: مصفوفة تصنيف مقدمي الخدمات (Service Providers Evaluation Matrix) | 8 |
| DOC §7 | سابعاً: خطوات العمل والجهات المعنية بالتنفيذ | 9 |
| DOC §8 | ثامناً: مؤشرات قياس الأثر والنجاح (Program KPIs) | 10 |

## 2. Labels

- `SOURCE-REQUIRED`: the business concept is stated explicitly in a source file.
- `OWNER-APPROVED`: decided explicitly by the project owner (recorded with date and ADR).
- `PROPOSED`: a technical design suggestion. It needs review and approval.
- `OPEN-QUESTION`: a missing decision that must not be guessed. See [open-questions.md](open-questions.md).

> **Important:** DOC is a program and business-model description. It is not a software specification, and it never describes screens, accounts, APIs or data fields. `SOURCE-REQUIRED` therefore means the *business concept* is in the source. **How the platform implements it is always `PROPOSED`** until the owner confirms it.

## 3. Matrix

Column key: **Module** = the planned logical module and phase (see [architecture.md](architecture.md)). **Endpoint/Test** = `—` until implemented.

### 3.1 Actors and organizations

| ID | Requirement | Source | Label | Module (phase) | Endpoint / Test | Status |
| --- | --- | --- | --- | --- | --- | --- |
| R-ACT-01 | The supervising organization is the Industrial Modernisation Centre (مركز تحديث الصناعة, IMC). Ministry of Industry branding appears on every page. | DOC p.1–10 header/footer; §1 | SOURCE-REQUIRED | Identity & Access (P3) | `imc_admin` role; `GET /me` / `Policies/*Test`, `CurrentUserTest` | **Implemented (P3)** as the IMC administrator role |
| R-ACT-02 | The IMC units named in the workflow are إدارة التحول الرقمي (Digital Transformation Dept), إدارة التنافسية والإنتاجية (Competitiveness & Productivity Dept), الإدارة التنفيذية (Executive Management) and خبراء تقييم معتمدون (certified assessment experts). | DOC §7 p.9 | SOURCE-REQUIRED (units); platform roles OPEN-QUESTION | Identity & Access (P3) | — / — | Interim: one `imc_admin` role with named permissions (D3); departments blocked: [OQ-21](open-questions.md#oq-21) |
| R-ACT-03 | The target clients are industrial companies: small, medium and large (الشركات الصناعية: الصغيرة، المتوسطة، والكبيرة). | DOC §1 p.2 (p.1 says small and medium only) | SOURCE-REQUIRED; size scope OPEN-QUESTION | Organizations: Factories (P5) | `/factories` (`size`, `filter[size]`), `/reference/factory-sizes` / `FactoryTest`, `ReferenceDataTest` | **Implemented (OQ-04 interim)**: declared size from a configurable list (small, medium, large), never derived; whether `large` is in scope: [OQ-04](open-questions.md#oq-04) |
| R-ACT-04 | Service-provider types are مقدمي الخدمات الاستشارية (consulting service providers) and شركاء التكنولوجيا (SPs) (technology partners). | DOC §7 p.9 | SOURCE-REQUIRED | Organizations: Providers (P4) | `/service-providers` / `ServiceProviderTest`, `ServiceProviderPolicyTest` | **Provider accounts implemented (P3)**; provider type (consulting vs technology partner) not modelled: [OQ-20](open-questions.md#oq-20) |
| R-ACT-05 | Factory management (إدارة المصنع) takes part in impact measurement. | DOC §7 step 5 | SOURCE-REQUIRED | Impact Measurement (P5/P8) | — | Not started |

### 3.2 Industrial sectors

| ID | Requirement | Source | Label | Module (phase) | Endpoint / Test | Status |
| --- | --- | --- | --- | --- | --- | --- |
| R-SEC-01 | Strategic target sectors: الصناعات الغذائية (food), الصناعات الكيماوية (chemical), الصناعات الهندسية والمعدنية (engineering & metal), الصناعات الطبية والدوائية (medical & pharmaceutical). | DOC §1 p.2 | SOURCE-REQUIRED | Reference Data (P2): `sectors`, `SectorSeeder` | No endpoint yet / `ReferenceDataSeederTest` | **Data implemented (P2)** |
| R-SEC-02 | DOC also mentions الزراعية (agricultural), but only as a traceability use case. It is not in the sector list. | DOC §3 p.4 (Advanced DX) | OPEN-QUESTION | Reference Data (P2) | — | Blocked: [OQ-05](open-questions.md#oq-05) |

### 3.3 Transformation path design

| ID | Requirement | Source | Label | Module (phase) | Endpoint / Test | Status |
| --- | --- | --- | --- | --- | --- | --- |
| R-PATH-01 | There is no one-size-fits-all model. Each company gets a custom path based on five determinants: (1) حجم المنشأة وحجم عملياتها التشغيلية, (2) مستوى النضج التشغيلي والتكنولوجي الحالي, (3) القدرات الإدارية والتنظيمية وجاهزية بيئة العمل للتغيير, (4) القدرة الماليّة والاستثمارية للمنشأة, (5) الأهداف والأولويات الاستراتيجية للمجلس الإداري. | DOC §1 p.2 (محددات تصميم مسار التحول) | SOURCE-REQUIRED | Assessment & Classification (P5) | — | Not started |
| R-PATH-02 | The flexible-path methodology has four dimensions: Processes, Technology, Organization and Roadmap. | DOC §1 p.2 (منهجية المسارات المرنة) | SOURCE-REQUIRED | Assessment & Roadmaps (P5) | — | Not started |
| R-PATH-03 | Operating philosophy: "Optimize before Automating" (التحسين وتأسيس القواعد قبل الأتمتة والرقمنة). | DOC §1 p.1 | SOURCE-REQUIRED (principle); enforcement as a platform gate OPEN-QUESTION | Assessment & Classification (P5) | — | Blocked: [OQ-09](open-questions.md#oq-09) |

### 3.4 Assessment and diagnosis (entry gate)

| ID | Requirement | Source | Label | Module (phase) | Endpoint / Test | Status |
| --- | --- | --- | --- | --- | --- | --- |
| R-ASM-01 | Every factory's path starts with a diagnosis. Assessment is the entry gate to the project (بوابة الدخول للمشروع). | DOC §2 p.2 | SOURCE-REQUIRED | Assessment (P5) | `/factories/{id}/readiness-assessments` / `ReadinessAssessmentTest` | **Implemented by the readiness assessment (ADR-018)**, see R-RDA-*. The manual classification of ADR-016 is retired; its records stay readable at `/factories/{id}/assessments` |
| R-ASM-02 | A comprehensive readiness assessment (التقييم الشامل للجاهزية) uses a digital and industrial readiness index (مؤشر الجاهزية الرقمية والصناعية). It covers infrastructure, operations, leadership and existing systems. | DOC §2.1 | SOURCE-REQUIRED (concept, 4 areas); index defined by RDA | Assessment (P5) | `/readiness-questionnaire` | **Implemented from RDA (ADR-018)**: the owner-supplied index has five pillars (leadership, operations, technology & data, people, customers) ([OQ-06](open-questions.md#oq-06) answered) |
| R-ASM-03 | Determine the maturity score (درجة النضج, Maturity Score) and document a baseline of operational indicators. All later ROI is measured against that baseline. | DOC §2.2 | SOURCE-REQUIRED; indicators OPEN-QUESTION | Assessment / Impact Measurement (P5) | — | Blocked: [OQ-11](open-questions.md#oq-11) |
| R-ASM-04 | Infrastructure and cyber readiness audit (تدقيق البنية التحتية والجاهزية السيبرانية): internal networks (LAN/Wi-Fi), servers and existing systems, and IT/OT security gaps before integration. | DOC §2.3 | SOURCE-REQUIRED; data captured OPEN-QUESTION | Assessment (P5) | — | Blocked: [OQ-10](open-questions.md#oq-10) |
| R-ASM-05 | The Digital Transformation Dept and certified assessment experts carry out the assessment. DOC §3 calls it a field assessment (التقييم الميداني). | DOC §7 step 1; §3 p.3 | SOURCE-REQUIRED; expert validation OPEN-QUESTION | Assessment (P5) | `ReadinessAssessmentPolicyTest` | **Partly decided (owner, ADR-018)**: factories self-assess; IMC reads every result. Expert validation: [OQ-08](open-questions.md#oq-08) |

### 3.4a Digital readiness assessment (RDA)

| ID | Requirement | Source | Label | Module (phase) | Endpoint / Test | Status |
| --- | --- | --- | --- | --- | --- | --- |
| R-RDA-01 | Five pillars: القيادة والحوكمة (Strategy & Leadership), العمليات التشغيلية (Processes & Operations), التكنولوجيا والبيانات (Technology & Data), الثقافة والكوادر البشرية (Culture & People), تجربة العملاء (Customer Experience). | RDA §1, §4 | SOURCE-REQUIRED | `readiness_pillars`, `ReadinessAssessmentSeeder` | `/readiness-questionnaire` / `ReadinessAssessmentSourceTest`, `ReadinessAssessmentSeederTest` | **Implemented** |
| R-RDA-02 | Ten questions, two per pillar, each with four choices أ ب ج د worth 1, 2, 3 and 4 points. | RDA §2, §4 | SOURCE-REQUIRED | `readiness_questions`, `readiness_choices` | `ReadinessAssessmentSourceTest` (text and points read from the .docx) | **Implemented** |
| R-RDA-03 | The total is the sum of the ten answers, 10–40. | RDA §2 | SOURCE-REQUIRED | `ReadinessAssessmentController@store` | `ReadinessAssessmentTest` (10, 40, mixed, client values ignored) | **Implemented** (server-side only) |
| R-RDA-04 | Categories: B4 Automation (ما قبل الأتمتة) 10–17, Basic (مبتدئ / رقمنة أساسية) 18–25, Advanced (متقدم) 26–33, Smart (ذكي ومبتكر) 34–40, each with its description. | RDA §3 | SOURCE-REQUIRED | `readiness_categories`, `ReadinessQuestionnaire::categoryForScore()` | `Models/ReadinessQuestionnaireTest` (boundaries 10/17/18/25/26/33/34/40; 0, 9, 41 refused; every total exactly one category) | **Implemented** ([OQ-07](open-questions.md#oq-07) answered for these categories) |
| R-RDA-05 | The platform shows each result's recommendations: the category's focus, steps and services. | RDA §5 | SOURCE-REQUIRED | `readiness_recommendations` + pivot to `catalog_services` | `ReadinessAssessmentTest`, `ReadinessAssessmentSourceTest` (mapping rule) | **Implemented**: 48 lines, 45 mappings to existing catalog services; 4 lines the catalog does not offer ([OQ-42](open-questions.md#oq-42)) |
| R-RDA-06 | Recommended services lead to providers only within the marketplace rules. | RDA §5 with ADR-014 eligibility | OWNER (eligibility) | `filter[recommended]` on `/provider-directory` and `/catalog/services` | `ProviderDirectoryTest`, `CatalogTest` | **Implemented**: the filter narrows the eligible providers and never adds one |

### 3.5 Client tiers (Client Assessment & Tiers Matrix)

| ID | Requirement | Source | Label | Module (phase) | Endpoint / Test | Status |
| --- | --- | --- | --- | --- | --- | --- |
| R-TIER-01 | There are four maturity tiers: التأسيسي (Foundation Tier), الرقمي الأساسي (Basic DX Tier), المصنع المتقدم (Advanced DX Tier) and المصنع الذكي (Smart DX Tier). This confirms the master prompt's Foundation/Basic/Advanced/Smart terminology. | DOC §4 p.6 | SOURCE-REQUIRED | Reference Data (P2): `maturity_tiers`; Classification (P5) | No endpoint yet / `ReferenceDataSeederTest` | **Data implemented (P2)**; classification P5 |
| R-TIER-02 | Readiness-index score ranges are **qualitative only**: ضعيف / منخفض, متوسط الأدنى, متوسط الأعلى, متقدم / مرتفع. The source gives no numeric thresholds. | DOC §4 p.6 | SOURCE-REQUIRED (bands); numeric thresholds not stated for these tiers | `maturity_tiers.readiness_band_ar` (P2) | `ReferenceDataSeederTest` (thresholds NULL), `ReferenceDataConstraintsTest` (not mass-assignable) | **Bands stored (P2)**. Factories are classified by the RDA categories (R-RDA-04), which are kept separate from these tiers: [OQ-41](open-questions.md#oq-41) |
| R-TIER-03 | Each tier records the factory's operational state, its approved execution path (المسار التنفيذي المعتمد), and its time targets and expected impact. Examples: Foundation aims to cut waste by 20–30% in 3–6 months. Basic aims for results within 6 months. Advanced aims to raise OEE by up to 50%. Smart aims to minimise sudden failures. | DOC §4 p.6 | SOURCE-REQUIRED (descriptive reference data) | Reference Data (P2): `maturity_tiers`, `MaturityTierSeeder` | `ReferenceDataSeederTest` | **Data implemented (P2)** |
| R-TIER-04 | Client classification and roadmap: apply the client matrix and choose a path (foundational or digital) for 2–3 years. | DOC §7 step 2 | SOURCE-REQUIRED; roadmap structure OPEN-QUESTION | Roadmaps (P5) | — | Blocked: [OQ-12](open-questions.md#oq-12) |

### 3.6 Transformation pathways and levels

| ID | Requirement | Source | Label | Module (phase) | Endpoint / Test | Status |
| --- | --- | --- | --- | --- | --- | --- |
| R-PW-01 | **Path 1: المسار الأول: الخدمات الاستشارية التأسيسية (ما قبل الرقمنة)**, also called التمكين التأسيسي وتأهيل البنية التحتية (Foundational Pathway). It targets low-to-medium maturity. Scope: (1) التميز التشغيلي والتطوير المؤسسي (Operational Excellence & BPR): strategic planning & governance, BPR, Lean (5S, VSM). (2) تأهيل البنية التحتية الرقمية والتكنولوجية (OT/IT Infrastructure Readiness): industrial networks, servers & legacy systems, IIoT readiness. | DOC §3 p.3 | SOURCE-REQUIRED | Reference Data (P2): `pathways`, `pathway_levels`, `pathway_scope_items` | `ReferenceDataSeederTest` | **Data implemented (P2)** |
| R-PW-02 | **Path 2: المسار الثاني: خدمات التحول الرقمي والتكنولوجي** is for factories that have passed the foundation stage. It is delivered as SaaS under the "Three Zeros" (الثلاثة أصفار): zero capital investment, zero infrastructure, zero disruption. | DOC §3 p.4 | SOURCE-REQUIRED | Reference Data (P2): `pathways` | `ReferenceDataSeederTest` | **Data implemented (P2)** |
| R-PW-03 | **Level أ. المستوى الأساسي (Basic DX – المؤسسة الرقمية)** has 6 scope items: digitise core functions and link departments; ERP for accounts, purchasing, warehouses and sales; HRMS & payroll; CRM; electronic archiving; Executive Dashboards. It has 3 provider requirements: ≥ 5 years of ERP and HRMS for the industrial sector; a customisable, fully SaaS-capable licence; integration via APIs. | DOC §3 p.4 | SOURCE-REQUIRED | Reference Data (P2): `pathway_levels`, `pathway_scope_items`, `level_provider_requirements`; Provider Evaluation (P4) | `ReferenceDataSeederTest` | **Data implemented (P2)**; use in evaluation P4 |
| R-PW-04 | **Level ب. المستوى المتقدم (Advanced DX – المصنع المتقدم)** has 7 scope items: industrial networks for real-time data; IT/OT network separation; digitising production lines and connecting machines; MES; IIoT sensors; Advanced/Med-Traceability; digitising internal supply chains within the supplier-development programme. It has 3 provider requirements: proven OT/IT integration, MES & IIoT; industrial protocols (OPC-UA, Modbus, MQTT); local and international quality/traceability standards. | DOC §3 p.4 | SOURCE-REQUIRED | Reference Data (P2): `pathway_levels`, `pathway_scope_items`, `level_provider_requirements`; Provider Evaluation (P4) | `ReferenceDataSeederTest` | **Data implemented (P2)**; use in evaluation P4 |
| R-PW-05 | **Level ج. المستوى الذكي (Smart DX – المصنع الذكي)** has 6 scope items: Big Data analytics & prediction; AI-driven operations & HR; Digital Twin & simulation; Predictive Maintenance; OT/ICS security; IDS/IPS supply & installation. It has 3 provider requirements: prior AI, digital-twin and predictive-maintenance work in industry; full commitment to ISO/IEC 62443; specialised big-data and OT-security staff. | DOC §3 p.5 | SOURCE-REQUIRED | Reference Data (P2): `pathway_levels`, `pathway_scope_items`, `level_provider_requirements`; Provider Evaluation (P4) | `ReferenceDataSeederTest` | **Data implemented (P2)**; use in evaluation P4 |
| R-PW-06 | Whether the per-level provider requirements are hard eligibility gates or evaluation inputs, and how they are verified. | DOC §3 p.4–5 | OPEN-QUESTION | Provider Evaluation (P4) | — | Blocked: [OQ-14](open-questions.md#oq-14) |

### 3.7 Commercial model and revenue sharing

| ID | Requirement | Source | Label | Module (phase) | Endpoint / Test | Status |
| --- | --- | --- | --- | --- | --- | --- |
| R-COM-01 | A revenue-sharing model with service providers, subject to IMC financial and legal regulations and its contracting bylaws (لائحة التعاقدات). | DOC §5 p.7 | SOURCE-REQUIRED (concept) | Finance (P7) | — | Blocked: [OQ-15](open-questions.md#oq-15) |
| R-COM-02 | Initial split (نسب التوزيع المبدئية): 70–80% to the provider and 20–30% to IMC. **Must not be encoded.** The source itself calls it "initial". | DOC §5 p.7 | OPEN-QUESTION | Finance (P7) | — | Blocked: [OQ-15](open-questions.md#oq-15) |
| R-COM-03 | IMC's share covers client attraction and qualification, provider selection, assessments, roadmaps, programme design, project governance, QA and monitoring. | DOC §5 p.7 | SOURCE-REQUIRED (descriptive) | — | — | Informational |
| R-COM-04 | Strategic recommendation: a variable share based on project size, service nature and operating cost; avoid one fixed rate. | DOC §5 p.7 | OPEN-QUESTION (recommendation, not a decision) | Finance (P7) | — | Blocked: [OQ-15](open-questions.md#oq-15) |
| R-COM-05 | A flexible cloud financing mechanism (آلية تمويل سحابية مرنة) that removes CapEx from factories. The source gives no mechanics. | DOC §1 p.1 | OPEN-QUESTION | Finance (P7) | — | Blocked: [OQ-16](open-questions.md#oq-16) |

### 3.8 Service-provider evaluation

| ID | Requirement | Source | Label | Module (phase) | Endpoint / Test | Status |
| --- | --- | --- | --- | --- | --- | --- |
| R-PEV-01 | Five weighted criteria (total 100%): الخبرة الفنية وسابقة الأعمال 30%; النموذج التقني والسحابي (SaaS) 25%; التزام "نقل المعرفة" (Knowledge Transfer) 20%; المرونة المالية ونموذج المشاركة 15%; الدعم الفني ومستويات الخدمة (SLA) 10%. Each criterion has stated sub-elements and verification mechanisms. | DOC §6 p.8 | SOURCE-REQUIRED (criteria & weights); scoring scale & pass mark OPEN-QUESTION | Reference Data (P2): `evaluation_criteria` v1; Provider Evaluation | `/reference/evaluation-criteria`, `/service-providers/{id}/evaluations` / `ReferenceDataSeederTest`, `ProviderEvaluationTest` | **Evaluations implemented (OQ-13 interim)**: a written assessment per criterion; scores, the weighted total and the pass-mark result only once the owner sets the scale and pass mark ([OQ-13](open-questions.md#oq-13)) |
| R-PEV-02 | Knowledge transfer: the provider trains at least two IMC engineers in the field throughout execution. Evidence is a detailed training plan and a legal commitment memo attached to the contract. | DOC §6 p.8 | SOURCE-REQUIRED | Contracts (ADR-017) | `/agreements/{id}/contracts` / `AgreementAndContractTest` | **Implemented in contract drafts**: at least 2 IMC engineers and a training plan required; the memo attachment waits for documents ([OQ-10](open-questions.md#oq-10), [OQ-17](open-questions.md#oq-17)) |
| R-PEV-03 | Providers are selected through the evaluation matrix, and systems are activated on SaaS with mandatory knowledge transfer. | DOC §7 step 4 | SOURCE-REQUIRED; read with the owner's marketplace decision | Provider approval (P4), Marketplace (P6) | `/service-providers/{id}/approval`, `/provider-directory` / `ProviderApprovalTest`, `ProviderDirectoryTest` | **Implemented as IMC approval before visibility (P4, ADR-014)**; the factory then chooses among approved providers (P6). Evaluation scoring blocked: [OQ-13](open-questions.md#oq-13) |

### 3.9 Programme workflow

| ID | Requirement | Source | Label | Module (phase) | Endpoint / Test | Status |
| --- | --- | --- | --- | --- | --- | --- |
| R-WF-01 | Five-step workflow: (1) التقييم والتشخيص; (2) تصنيف العميل وخارطة الطريق; (3) التمكين التأسيسي (إن وجد); (4) التنفيذ الرقمي بشراكة مقدمي الخدمة; (5) القياس والاستدامة. | DOC §7 p.9 | SOURCE-REQUIRED (steps); platform state machine PROPOSED | Engagement workflow (P6), see [workflows](architecture.md#5-proposed-workflow-backbone) | — | Not started |
| R-WF-02 | Responsible units per step, as listed in DOC §7. | DOC §7 p.9 | SOURCE-REQUIRED | Identity & Access (P3) | — | Blocked: [OQ-21](open-questions.md#oq-21) |
| R-WF-03 | Measure actual impact after 6 and 12 months against the performance targets. | DOC §7 step 5 | SOURCE-REQUIRED | Impact Measurement (P5/P8) | — | Blocked: [OQ-11](open-questions.md#oq-11) |

### 3.10 KPIs

| ID | Requirement | Source | Label | Module (phase) | Endpoint / Test | Status |
| --- | --- | --- | --- | --- | --- | --- |
| R-KPI-01 | Growth rate of the Digital Maturity Index for establishments (%). | DOC §8 p.10 | SOURCE-REQUIRED | Reporting (P8) | — | Blocked: [OQ-06](open-questions.md#oq-06), [OQ-27](open-questions.md#oq-27) |
| R-KPI-02 | OEE improvement of 15–25%, labour efficiency +30%, and reduced operational waste and total energy bill (%). | DOC §8 p.10 | SOURCE-REQUIRED (targets) | Reporting (P8) | — | Blocked: [OQ-11](open-questions.md#oq-11), [OQ-27](open-questions.md#oq-27) |
| R-KPI-03 | Number of factories qualified and integrated in the SaaS ecosystem (count). | DOC §8 p.10 | SOURCE-REQUIRED | Reporting (P8) | — | Blocked: [OQ-27](open-questions.md#oq-27) |
| R-KPI-04 | IMC target of 20% annual revenue growth. This is a business target, not a platform rule. | DOC §8 p.10 | SOURCE-REQUIRED (informational) | — | — | Informational |
| R-KPI-05 | Knowledge-transfer sustainability for IMC staff. | DOC §8 p.10 | SOURCE-REQUIRED (informational) | — | — | Informational |

## 4. Master-prompt scope not found in DOC

The master prompt lists these platform capabilities, but DOC does not describe them. Each must be confirmed against WB (once supplied) or by the owner before it is built.

| ID | Capability | Found in DOC? | Label | Blocked by |
| --- | --- | --- | --- | --- |
| R-PLT-01 | User accounts, sign-up/sign-in, password recovery | No | **Implemented (P3)**: login/logout, password reset, admin-provisioned accounts (D1), bearer tokens (D2). Endpoints: `/auth/*`, `/me`, `/users`. Tests: `Auth/*Test`, `UserTest`, `UserPolicyTest` | Interim decisions: [OQ-18](open-questions.md#oq-18), [OQ-22](open-questions.md#oq-22), [OQ-33](open-questions.md#oq-33) |
| R-PLT-02 | Factory profile fields | Only size and sector are implied; the workbook defines none | Minimal (P3): name + sectors (D4); **factory members edit them since P5** (owner decision); other fields OPEN-QUESTION | [OQ-19](open-questions.md#oq-19) |
| R-PLT-03 | Provider profile fields (company name, representative, job title, email, phone, website, years of DX experience, target sectors) | **Yes, in WB rows 1–9** (provider form) | **Implemented (P4, ADR-014)**: all workbook fields, all optional except the name ([OQ-36](open-questions.md#oq-36)); provider members edit their own. Tests: `ServiceProviderTest` | — |
| R-PLT-04 | Service categories and sub-services catalog | **Yes, WB rows 11–61** | **Implemented (P4, ADR-014)**: 7 categories, 42 services seeded from WB, checked cell by cell against the file. Endpoints: `/catalog/*`. Tests: `ServiceCatalogSourceTest`, `CatalogTest` | — |
| R-PLT-05 | Factory service requests, provider offers and negotiation | **No** (DOC describes IMC selection); **OWNER-APPROVED marketplace** (2026-10-03) | **Implemented (P6, ADR-015)**: requests to one or many eligible providers, per-provider accept/decline, private messages, offer versions, acceptance of the latest offer. States PROPOSED ([OQ-38](open-questions.md#oq-38)). Tests: `ServiceRequestTest`, `ProviderRequestTest`, `NegotiationTest` | [OQ-38](open-questions.md#oq-38), [OQ-39](open-questions.md#oq-39) |
| R-PLT-06 | Three-party electronic contracts | Partly. DOC refers to "the contract" (§6) and IMC contracting bylaws (§5), but names no parties and no e-signature. | OPEN-QUESTION. **Boundaries built (ADR-017):** an immutable agreement per accepted offer; contract drafts, versioned and never binding; no signature or approval state (tested) | [OQ-17](open-questions.md#oq-17) |
| R-PLT-07 | Invoices and payments | No | OPEN-QUESTION. **Boundaries built (ADR-017):** invoices (exact decimal money, gap-free numbers) and payments (idempotent start, verified gateway evidence only); every money operation answers 409 `policy_not_configured` until the owner sets its rule; no gateway adapter ships | [OQ-15](open-questions.md#oq-15), [OQ-16](open-questions.md#oq-16) |
| R-PLT-08 | Notifications | No | PROPOSED; channels OPEN-QUESTION | [OQ-26](open-questions.md#oq-26) |
| R-PLT-09 | Audit trail of sensitive actions | No | **Implemented (P8 slice)**: 16 security events; `GET /audit-logs` for administrators ([ADR-012](decisions/ADR-012-audit-log.md)). Tests: `Audit/AuditTrailTest`, `Api/V1/AuditLogTest`, `Models/AuditLogTest`, `Policies/AuditLogPolicyTest` | Retention: [OQ-25](open-questions.md#oq-25) |
| R-PLT-10 | Administrative reporting | Implied by KPIs (§8) | PROPOSED | [OQ-27](open-questions.md#oq-27) |
| R-PLT-11 | Provider review/approval workflow | Evaluation is in DOC (§6); the approval workflow is not | **Implemented:** manual IMC approval (P4), review queue and re-review request after a rejection (PROPOSED), evaluations (OQ-13 interim), configurable required fields (OQ-36) | [OQ-13](open-questions.md#oq-13), [OQ-36](open-questions.md#oq-36) |
| R-PLT-12 | Training/qualification programmes (DOC §1 mentions training & qualification programmes) | Mentioned only | OPEN-QUESTION (in platform scope?) | [OQ-31](open-questions.md#oq-31) |

## 5. Service catalog

Source: the services workbook `docs/Copy of الخدمات التحول الرقمي.xlsx`, Sheet1, section «هيكلية خدمات التحول الرقمي والتصنيع الذكي (Industry 4.0)». It was read in full in Phase 4; until then it had been wrongly reported as missing ([OQ-01](open-questions.md#oq-01)).

- **Verification:** every category and service is seeded from it (`ServiceCatalogSeeder`) and compared with the file itself by `ServiceCatalogSourceTest`.
- **The master prompt's seven categories match the workbook exactly.**

| # | Code | Arabic (WB) | English (WB, in parentheses) | Services | WB cells |
| --- | --- | --- | --- | --- | --- |
| 1 | `erp_business_applications` | نظم تخطيط وإدارة موارد المؤسسات والتطبيقات الرقمية | ERP & Business Applications | 5 | C12; C13–C17 |
| 2 | `automation_ot` | الأتمتة والنظم التشغيلية الصناعية | Automation & OT | 8 | C18; C19–C26 |
| 3 | `cloud_infrastructure` | النظم السحابية والبنية التحتية الرقمية | Cloud & Infrastructure | 5 | C27; C28–C32 |
| 4 | `ot_ics_cybersecurity` | الأمن السيبراني الصناعي | OT/ICS Cybersecurity | 7 | C33; C34–C40 |
| 5 | `ai_data_analytics` | الذكاء الاصطناعي والبيانات والتحليلات المتقدمة | AI, Data & Analytics | 7 | C41; C42–C48 |
| 6 | `digital_engineering_smart_manufacturing` | الهندسة الرقمية وتقنيات التصنيع الذكي | Digital Engineering & Smart Manufacturing | 5 | C49; C50–C54 |
| 7 | `dx_consulting_enablement` | الاستشارات ومُمكّنات التحول الرقمي | Digital Transformation Consulting & Enablement | 5 | C55; C56–C61 |

Notes:
- **Rows 59–60** («إدارة التدريب» and « والثقافة الرقمية.») are **one** service, by owner decision (2026-10-03). The workbook had 43 service rows; the catalog has 42 services.
- **«إعادة هندسة ورقمنة العمليات.»** appears in categories 1 and 7. Both are kept, as two services.
- **The source's own wording is kept exactly.** That includes trailing full stops, the spacing in «(  computer vision )», and «الحماية من هجمات والتهديدات السيبرانية.».
- **Which English is kept:** the categories' English labels come from the workbook. Service names keep the English abbreviations the workbook prints inside the Arabic text, and get no separate English name (interim OQ-23).

## 6. Inconsistencies found within DOC

| # | Observation | Refs | Tracked as |
| --- | --- | --- | --- |
| I-1 | p.1 says the target is small and medium establishments (المنشآت الصناعية الصغيرة والمتوسطة). p.2 adds large companies. | DOC §1 p.1 vs p.2 | [OQ-04](open-questions.md#oq-04) |
| I-2 | §5 puts IMC's share at 20–30%. §6 has providers accept revenue sharing of 5%–20% "depending on the centre's role". | DOC §5 p.7 vs §6 p.8 | [OQ-15](open-questions.md#oq-15) |
| I-3 | Advanced tier targets OEE gains of "up to 50%" (§4), while the programme KPI is 15–25% (§8). These may be different scopes (tier ceiling vs programme average). | DOC §4 p.6 vs §8 p.10 | [OQ-27](open-questions.md#oq-27) |
| I-4 | "Agricultural" appears in a traceability example but not in the sector list. | DOC §3 p.4 vs §1 p.2 | [OQ-05](open-questions.md#oq-05) |

## 7. Exact source terminology

Use these terms verbatim in seeders, enums' display labels and API documentation. The fuller list, including database `code` values, is in [glossary.md](glossary.md).

| Concept | Arabic (DOC) | English (DOC) |
| --- | --- | --- |
| Initiative | مبادرة "التحول الصناعي الذكي" | Smart Industry – Ecosystem |
| Supervising org | مركز تحديث الصناعة | Industrial Modernisation Centre (IMC) |
| Readiness index | مؤشر الجاهزية الرقمية والصناعية | (digital & industrial readiness index) |
| Maturity score | درجة النضج | Maturity Score |
| Baseline | خط الأساس | Baseline |
| Infra & cyber audit | تدقيق البنية التحتية والجاهزية السيبرانية | Infrastructure & Cyber Readiness Audit |
| Pathways | مسارات تقديم الخدمة | The Transformation Pathways |
| Path 1 | المسار الأول: الخدمات الاستشارية التأسيسية (ما قبل الرقمنة) / التمكين التأسيسي وتأهيل البنية التحتية | Foundational Pathway |
| Path 2 | المسار الثاني: خدمات التحول الرقمي والتكنولوجي | (digital & technological transformation services) |
| Level a | المستوى الأساسي – المؤسسة الرقمية | Basic DX |
| Level b | المستوى المتقدم – المصنع المتقدم | Advanced DX |
| Level c | المستوى الذكي – المصنع الذكي | Smart DX |
| Tiers | التأسيسي / الرقمي الأساسي / المصنع المتقدم / المصنع الذكي | Foundation / Basic DX / Advanced DX / Smart DX Tier |
| Three Zeros | الثلاثة أصفار (صفر استثمار رأسمالي، صفر بنية تحتية، صفر تعطيل) | — |
| Roadmap | خارطة الطريق | Roadmap |
| Provider evaluation | مصفوفة تصنيف مقدمي الخدمات | Service Providers Evaluation Matrix |
| Knowledge transfer | نقل المعرفة | Knowledge Transfer |
| Technology partners | شركاء التكنولوجيا | SPs |
