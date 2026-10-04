# Glossary

Project terms in Arabic and English. **Arabic is the authoritative text.** English appears only where the source document (التحول الصناعي الذكي) prints it. Otherwise the English column gives a working translation, marked *(working)*, which must not be used as stored data until [OQ-23](open-questions.md#oq-23) is decided. The `code` column is the stable key used in the database.

## Organizations and roles

| Arabic | English | Notes |
| --- | --- | --- |
| مركز تحديث الصناعة | Industrial Modernisation Centre (IMC) | Supervising organization |
| وزارة الصناعة | Ministry of Industry | Appears in the source branding |
| إدارة التحول الرقمي | Digital Transformation Department *(working)* | IMC unit (DOC §7) |
| إدارة التنافسية والإنتاجية | Competitiveness & Productivity Department *(working)* | IMC unit (DOC §7) |
| الإدارة التنفيذية | Executive Management *(working)* | IMC unit (DOC §7) |
| خبراء تقييم معتمدون | Certified assessment experts *(working)* | DOC §7 step 1 |
| مقدمي الخدمات الاستشارية | Consulting service providers *(working)* | DOC §7 step 3 |
| شركاء التكنولوجيا | Technology partners (SPs) | DOC §7 step 4 |
| إدارة المصنع | Factory management *(working)* | DOC §7 step 5 |

## Platform roles (Phase 3, [roles-permissions.md](roles-permissions.md))

| `code` | Arabic | English |
| --- | --- | --- |
| `imc_admin` | مسؤول منصة مركز تحديث الصناعة *(working)* | IMC administrator |
| `factory_member` | عضو المصنع *(working)* | Factory member |
| `provider_member` | عضو مقدم الخدمة *(working)* | Service-provider member |

## Programme concepts

| Arabic | English | Notes |
| --- | --- | --- |
| مبادرة "التحول الصناعي الذكي" | Smart Industry – Ecosystem | The initiative |
| منظومة بيئية صناعية رقمية متكاملة | Digital Industrial Ecosystem | DOC §1 |
| التحسين وتأسيس القواعد قبل الأتمتة والرقمنة | Optimize before Automating | Operating philosophy |
| الثلاثة أصفار (صفر استثمار رأسمالي، صفر بنية تحتية، صفر تعطيل) | "Three Zeros" *(working)* | SaaS delivery principle |
| آلية التقييم والتشخيص | Assessment & diagnosis mechanism *(working)* | Entry gate (DOC §2) |
| مؤشر الجاهزية الرقمية والصناعية | Digital & industrial readiness index *(working)* | Formula open ([OQ-06](open-questions.md#oq-06)) |
| درجة النضج | Maturity Score | DOC §2.2 |
| خط الأساس | Baseline | DOC §2.2 |
| تدقيق البنية التحتية والجاهزية السيبرانية | Infrastructure & Cyber Readiness Audit | DOC §2.3 |
| خارطة الطريق | Roadmap | DOC §1, §7 |
| نقل المعرفة | Knowledge Transfer | DOC §6 |
| المشاركة في العائد | Revenue Sharing | DOC §6 (rule open, [OQ-15](open-questions.md#oq-15)) |
| مستويات الخدمة | Service levels (SLA) | DOC §6 |
| مؤشرات قياس الأثر والنجاح | Program KPIs | DOC §8 |

## Reference data (seeded; see [data-model.md §4](data-model.md#4-reference-data-seeded-in-phase-2))

| Set | `code` | Arabic | English (source) |
| --- | --- | --- | --- |
| Sector | `food` | الصناعات الغذائية | — |
| Sector | `chemical` | الصناعات الكيماوية | — |
| Sector | `engineering_metal` | الصناعات الهندسية والمعدنية | — |
| Sector | `medical_pharmaceutical` | الصناعات الطبية والدوائية | — |
| Pathway | `foundational` | المسار الأول: الخدمات الاستشارية التأسيسية (ما قبل الرقمنة) | — |
| Pathway | `digital_transformation` | المسار الثاني: خدمات التحول الرقمي والتكنولوجي | — |
| Level | `foundational` | التمكين التأسيسي وتأهيل البنية التحتية | Foundational Pathway |
| Level | `basic_dx` | المستوى الأساسي (المؤسسة الرقمية) | Basic DX |
| Level | `advanced_dx` | المستوى المتقدم (المصنع المتقدم) | Advanced DX |
| Level | `smart_dx` | المستوى الذكي (المصنع الذكي) | Smart DX |
| Tier | `foundation` | التأسيسي | Foundation Tier |
| Tier | `basic_dx` | الرقمي الأساسي | Basic DX Tier |
| Tier | `advanced_dx` | المصنع المتقدم | Advanced DX Tier |
| Tier | `smart_dx` | المصنع الذكي | Smart DX Tier |
| Criterion | `technical_expertise` | الخبرة الفنية وسابقة الأعمال (30%) | — |
| Criterion | `technical_cloud_model` | النموذج التقني والسحابي (SaaS) (25%) | — |
| Criterion | `knowledge_transfer` | التزام "نقل المعرفة" (Knowledge Transfer) (20%) | — |
| Criterion | `financial_flexibility` | المرونة المالية ونموذج المشاركة (15%) | — |
| Criterion | `technical_support_sla` | الدعم الفني ومستويات الخدمة (SLA) (10%) | — |

## Technical terms used in the source

| Term | Meaning |
| --- | --- |
| BPR | Business Process Re-engineering (إعادة هندسة العمليات) |
| Lean / 5S / VSM | Lean manufacturing; workplace organisation; value-stream mapping |
| ERP / HRMS / CRM | Enterprise resource planning; HR management system; customer relationship management |
| MES | Manufacturing execution system (نظم تنفيذ التصنيع) |
| IIoT | Industrial Internet of Things (إنترنت الأشياء الصناعي) |
| OEE | Overall equipment effectiveness (الفاعلية الشاملة للمعدات) |
| IT / OT / ICS | Information technology / operational technology / industrial control systems |
| IDS / IPS | Intrusion detection / prevention systems |
| ISO/IEC 62443 | Industrial automation and control systems security standard |
| SaaS | Software as a service (الحلول السحابية) |
