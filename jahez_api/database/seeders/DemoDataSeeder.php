<?php

namespace Database\Seeders;

use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Models\Agreement;
use App\Models\AuditLog;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\FactoryProfileChangeRequest;
use App\Models\Offer;
use App\Models\ProviderProfileChangeRequest;
use App\Models\ProviderRequest;
use App\Models\PublicAnnouncement;
use App\Models\ReadinessQuestion;
use App\Models\ReadinessQuestionnaire;
use App\Models\Sector;
use App\Models\ServicePromotion;
use App\Models\ServiceProvider;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Synthetic, interconnected demo data for a local or dedicated demo database (ADR-024):
 * 16 factories, 8 service providers with 20 service listings, 16 readiness assessments in
 * all four categories, 10 service requests with negotiations, offers and 4 agreements (one
 * with a non-binding contract draft),
 * promotions, landing-page announcements and legal change requests.
 *
 * - **Explicit only:** `php artisan db:seed --class=DemoDataSeeder`. DatabaseSeeder never
 *   calls it, and it refuses to run outside the `local` and `testing` environments,
 *   because every account it creates uses the publicly known password "password".
 * - **Same rules as production:** each organization is registered as the registration job
 *   does it (pending, audited, IMC notified). Every later step (IMC decisions, readiness
 *   submissions, requests, negotiation, offers, agreement reviews, promotions,
 *   announcements, change requests) is sent through the real API endpoints, in-process, as
 *   the demo user who would take it. Validation, eligibility, approval gates, scoring
 *   (ReadinessAssessmentRecorder), audit entries and notifications are therefore exactly
 *   those of production; a step the rules refuse stops the seeder.
 * - **No money, no binding contract:** no financial policy, invoice or payment is created;
 *   invoicing stays blocked (409 `policy_not_configured`, OQ-15–16). The one contract is a
 *   draft on the agreement IMC approved, which the rules allow; drafts are never binding
 *   (OQ-17).
 * - **No documents:** no logo or registration document is fabricated.
 * - **Level services are demo choices (ADR-025):** IMC makes each readiness level's
 *   roadmap-recommended catalog services available, plus each scripted request's service
 *   at the requesting factory's level, through the API. No deployment seeds them.
 * - **Idempotent, create-only:** every demo account uses the `demo.jahez.test` domain. An
 *   organization whose member account exists is skipped with everything scripted for it;
 *   requests are matched by factory and title, promotions by provider, service and
 *   headline, announcements by title, change requests by their note. Existing records,
 *   demo or real, are never updated, so changes made in the UI survive a re-run.
 * - **Timeline:** events are dated over the last nine months (the clock is moved for each
 *   step and restored afterwards), so the analytics and histories read naturally.
 */
class DemoDataSeeder extends Seeder
{
    public const EMAIL_DOMAIN = 'demo.jahez.test';

    private const REVIEWER = ['email' => 'imc-reviewer@demo.jahez.test', 'name' => 'منسق المراجعة بالمركز'];

    /**
     * Demo service providers. `decision` is IMC's account decision (null: still pending).
     * `listings` maps a catalog service code to IMC's listing decision (null: pending).
     *
     * @var array<string, array{name: string, legal_name: string, description: string, representative_name: string, job_title: string, phone: string, dx_experience_years: int, governorate: string, city: string, address: string, sectors: list<string>, listings: array<string, string|null>, registered: int, decision: array{0: string, 1: string|null}|null}>
     */
    private const PROVIDERS = [
        'p01' => [
            'name' => 'النيل للحلول الرقمية الصناعية',
            'legal_name' => 'شركة النيل للحلول الرقمية الصناعية (ش.م.م)',
            'description' => 'شركة متخصصة في تطبيق نظم تخطيط موارد المؤسسات وأتمتة دورات العمل للمصانع المتوسطة والكبيرة، مع فريق دعم فني يعمل من داخل مواقع العملاء.',
            'representative_name' => 'هبة عبد العزيز',
            'job_title' => 'مديرة تطوير الأعمال',
            'phone' => '01000000201',
            'dx_experience_years' => 9,
            'governorate' => 'القاهرة',
            'city' => 'التجمع الخامس',
            'address' => 'المبنى الإداري ٤، شارع التسعين الشمالي',
            'sectors' => ['food', 'chemical'],
            'listings' => ['erp_business_applications.01' => 'approved', 'erp_business_applications.03' => 'approved', 'dx_consulting_enablement.01' => 'approved', 'erp_business_applications.05' => null],
            'registered' => 262,
            'decision' => ['approved', null],
        ],
        'p02' => [
            'name' => 'دلتا للأتمتة والتحكم الصناعي',
            'legal_name' => 'شركة دلتا للأتمتة والتحكم الصناعي',
            'description' => 'تصميم وتنفيذ نظم تنفيذ التصنيع والمراقبة والتحكم الصناعي، وربط الآلات وخطوط الإنتاج وجمع بيانات التشغيل.',
            'representative_name' => 'محمود الشربيني',
            'job_title' => 'مدير المشروعات',
            'phone' => '01000000202',
            'dx_experience_years' => 12,
            'governorate' => 'الجيزة',
            'city' => 'مدينة السادس من أكتوبر',
            'address' => 'المنطقة الصناعية الثالثة، قطعة ١٢',
            'sectors' => ['engineering_metal', 'food'],
            'listings' => ['automation_ot.01' => 'approved', 'automation_ot.02' => 'approved', 'automation_ot.03' => 'approved', 'automation_ot.05' => 'rejected'],
            'registered' => 255,
            'decision' => ['approved', null],
        ],
        'p03' => [
            'name' => 'حصن للأمن السيبراني الصناعي',
            'legal_name' => 'شركة حصن للأمن السيبراني الصناعي',
            'description' => 'تقييم الجاهزية في الأمن السيبراني واختبارات الاختراق وحماية شبكات وأنظمة التحكم الصناعي.',
            'representative_name' => 'ريهام فتحي',
            'job_title' => 'مديرة الأمن السيبراني',
            'phone' => '01000000203',
            'dx_experience_years' => 7,
            'governorate' => 'القاهرة',
            'city' => 'مدينة نصر',
            'address' => 'شارع مكرم عبيد، برج الأعمال، الدور السابع',
            'sectors' => ['chemical', 'medical_pharmaceutical', 'engineering_metal'],
            'listings' => ['ot_ics_cybersecurity.02' => 'approved', 'ot_ics_cybersecurity.03' => 'approved', 'ot_ics_cybersecurity.07' => 'suspended'],
            'registered' => 248,
            'decision' => ['approved', null],
        ],
        'p04' => [
            'name' => 'سحابة مصر للبنية الرقمية',
            'legal_name' => 'شركة سحابة مصر للبنية الرقمية',
            'description' => 'حلول سحابية وبنية تحتية رقمية ومراكز بيانات، وتخزين وإدارة البيانات للمنشآت الصناعية.',
            'representative_name' => 'كريم يوسف',
            'job_title' => 'مدير الحلول السحابية',
            'phone' => '01000000204',
            'dx_experience_years' => 6,
            'governorate' => 'الإسكندرية',
            'city' => 'سموحة',
            'address' => 'طريق ٢٦ يوليو، مجمع الأعمال',
            'sectors' => ['food', 'medical_pharmaceutical', 'chemical'],
            'listings' => ['cloud_infrastructure.01' => 'approved', 'cloud_infrastructure.03' => 'approved', 'cloud_infrastructure.04' => null],
            'registered' => 240,
            'decision' => ['approved', null],
        ],
        'p05' => [
            'name' => 'رؤية للذكاء الاصطناعي والتحليلات',
            'legal_name' => 'شركة رؤية للذكاء الاصطناعي والتحليلات',
            'description' => 'تطبيقات الذكاء الاصطناعي للصيانة التنبؤية والفحص البصري لضبط الجودة وتحليل البيانات التشغيلية.',
            'representative_name' => 'سارة منصور',
            'job_title' => 'مديرة علوم البيانات',
            'phone' => '01000000205',
            'dx_experience_years' => 5,
            'governorate' => 'الجيزة',
            'city' => 'الشيخ زايد',
            'address' => 'المحور المركزي، مبنى ٨',
            'sectors' => ['engineering_metal', 'food', 'medical_pharmaceutical'],
            'listings' => ['ai_data_analytics.02' => 'approved', 'ai_data_analytics.03' => 'approved', 'ai_data_analytics.04' => 'suspended'],
            'registered' => 232,
            'decision' => ['approved', null],
        ],
        'p06' => [
            'name' => 'المصنع الذكي للهندسة الرقمية',
            'legal_name' => 'شركة المصنع الذكي للهندسة الرقمية',
            'description' => 'حلول التوأم الرقمي والمحاكاة الرقمية للعمليات وخطوط الإنتاج.',
            'representative_name' => 'عمرو حلمي',
            'job_title' => 'المدير التنفيذي',
            'phone' => '01000000206',
            'dx_experience_years' => 3,
            'governorate' => 'القليوبية',
            'city' => 'العبور',
            'address' => 'المنطقة الصناعية، بلوك ١٣',
            'sectors' => ['engineering_metal'],
            'listings' => ['digital_engineering_smart_manufacturing.01' => null],
            'registered' => 6,
            'decision' => null,
        ],
        'p07' => [
            'name' => 'أفق للاستشارات والتحول الرقمي',
            'legal_name' => 'شركة أفق للاستشارات والتحول الرقمي',
            'description' => 'استشارات في استراتيجيات التحول الرقمي وبناء القدرات للمصانع الصغيرة والمتوسطة.',
            'representative_name' => 'نهى الجمال',
            'job_title' => 'شريكة استشارية',
            'phone' => '01000000207',
            'dx_experience_years' => 4,
            'governorate' => 'الدقهلية',
            'city' => 'المنصورة',
            'address' => 'شارع الجمهورية، عمارة ٣٠',
            'sectors' => ['food', 'chemical'],
            'listings' => ['dx_consulting_enablement.05' => 'rejected'],
            'registered' => 40,
            'decision' => ['changes_requested', 'يرجى استكمال وصف الخبرات السابقة في مشروعات التحول الرقمي الصناعي وتحديث بيانات التواصل.'],
        ],
        'p08' => [
            'name' => 'تكامل للنظم الصناعية',
            'legal_name' => 'شركة تكامل للنظم الصناعية',
            'description' => 'تكامل الأنظمة وربط التطبيقات في المنشآت الصناعية.',
            'representative_name' => 'حسام رجب',
            'job_title' => 'مدير المبيعات',
            'phone' => '01000000208',
            'dx_experience_years' => 2,
            'governorate' => 'الشرقية',
            'city' => 'الزقازيق',
            'address' => 'شارع فاروق، برج النور',
            'sectors' => ['chemical'],
            'listings' => ['erp_business_applications.02' => null],
            'registered' => 75,
            'decision' => ['rejected', 'لا تستوفي الخبرات الموضحة متطلبات مشروعات التحول الرقمي الصناعي في الوقت الحالي، ويمكن إعادة التقديم بعد استكمالها.'],
        ],
    ];

    /**
     * Listing decisions that need a reason, by provider and service code.
     *
     * @var array<string, string>
     */
    private const LISTING_REASONS = [
        'p02:automation_ot.05' => 'يرجى توضيح الخبرات السابقة في دمج الروبوتات التعاونية وإرفاق نماذج من مشروعات منفذة.',
        'p03:ot_ics_cybersecurity.07' => 'إيقاف مؤقت لحين تحديث محتوى البرنامج التدريبي ومراجعته.',
        'p05:ai_data_analytics.04' => 'إيقاف مؤقت بناءً على طلب المزود لإعادة تشكيل فريق التنفيذ.',
        'p07:dx_consulting_enablement.05' => 'لم تُرفق نماذج لبرامج بناء القدرات الرقمية التي نفذتها الشركة.',
    ];

    /**
     * Demo factories. `assessments` lists [total score, days ago]; each total is reached by
     * a deterministic set of answers and scored by the server. `decision` is IMC's account
     * decision; `suspended` adds a later suspension of an approved factory.
     *
     * @var array<string, array{name: string, legal_name: string, size: string, sectors: list<string>, governorate: string, city: string, address: string, contact_name: string, contact_job_title: string, contact_phone: string, registered: int, decision: array{0: string, 1: string|null}|null, suspended?: string, assessments: list<array{0: int, 1: int}>}>
     */
    private const FACTORIES = [
        'f01' => ['name' => 'مصنع النخبة للأغذية المحفوظة', 'legal_name' => 'شركة النخبة للأغذية المحفوظة (ش.م.م)', 'size' => 'large', 'sectors' => ['food'], 'governorate' => 'الجيزة', 'city' => 'مدينة السادس من أكتوبر', 'address' => 'المنطقة الصناعية الأولى، قطعة ٢٧', 'contact_name' => 'أحمد عبد الرحمن', 'contact_job_title' => 'مدير التحول الرقمي', 'contact_phone' => '01000000101', 'registered' => 250, 'decision' => ['approved', null], 'assessments' => [[40, 196]]],
        'f02' => ['name' => 'شركة الدلتا للصناعات الكيماوية', 'legal_name' => 'شركة الدلتا للصناعات الكيماوية', 'size' => 'large', 'sectors' => ['chemical'], 'governorate' => 'القليوبية', 'city' => 'العبور', 'address' => 'المنطقة الصناعية، بلوك ٢٢', 'contact_name' => 'منى سعيد', 'contact_job_title' => 'مديرة تكنولوجيا المعلومات', 'contact_phone' => '01000000102', 'registered' => 244, 'decision' => ['approved', null], 'assessments' => [[37, 180]]],
        'f03' => ['name' => 'مصانع الصعيد للصناعات المعدنية', 'legal_name' => 'شركة مصانع الصعيد للصناعات المعدنية', 'size' => 'medium', 'sectors' => ['engineering_metal'], 'governorate' => 'أسيوط', 'city' => 'عرب العوامر', 'address' => 'المنطقة الصناعية بعرب العوامر، قطعة ٩', 'contact_name' => 'خالد المنياوي', 'contact_job_title' => 'مدير المصنع', 'contact_phone' => '01000000103', 'registered' => 238, 'decision' => ['approved', null], 'assessments' => [[27, 205], [34, 58]]],
        'f04' => ['name' => 'شركة النيل للأدوية والمستحضرات الطبية', 'legal_name' => 'شركة النيل للأدوية والمستحضرات الطبية', 'size' => 'large', 'sectors' => ['medical_pharmaceutical'], 'governorate' => 'القاهرة', 'city' => 'مدينة بدر', 'address' => 'المنطقة الصناعية الثانية، قطعة ٤٤', 'contact_name' => 'د. ياسمين فوزي', 'contact_job_title' => 'مديرة الجودة', 'contact_phone' => '01000000104', 'registered' => 230, 'decision' => ['approved', null], 'assessments' => [[33, 150]]],
        'f05' => ['name' => 'مصنع الإسكندرية للمنظفات الصناعية', 'legal_name' => 'شركة الإسكندرية للمنظفات الصناعية', 'size' => 'medium', 'sectors' => ['chemical'], 'governorate' => 'الإسكندرية', 'city' => 'برج العرب الجديدة', 'address' => 'المنطقة الصناعية الثالثة، قطعة ١٥', 'contact_name' => 'تامر حسين', 'contact_job_title' => 'مدير العمليات', 'contact_phone' => '01000000105', 'registered' => 225, 'decision' => ['approved', null], 'assessments' => [[21, 190], [30, 46]]],
        'f06' => ['name' => 'شركة القناة للصناعات الهندسية', 'legal_name' => 'شركة القناة للصناعات الهندسية', 'size' => 'medium', 'sectors' => ['engineering_metal'], 'governorate' => 'الإسماعيلية', 'city' => 'الإسماعيلية', 'address' => 'المنطقة الصناعية بالإسماعيلية، قطعة ٦', 'contact_name' => 'وليد إبراهيم', 'contact_job_title' => 'مدير الصيانة', 'contact_phone' => '01000000106', 'registered' => 218, 'decision' => ['approved', null], 'assessments' => [[26, 140]]],
        'f07' => ['name' => 'مصنع الشرقية لمنتجات الألبان', 'legal_name' => 'شركة الشرقية لمنتجات الألبان', 'size' => 'medium', 'sectors' => ['food'], 'governorate' => 'الشرقية', 'city' => 'العاشر من رمضان', 'address' => 'المنطقة الصناعية B3، قطعة ٧١', 'contact_name' => 'إيمان الشافعي', 'contact_job_title' => 'مديرة التخطيط', 'contact_phone' => '01000000107', 'registered' => 210, 'decision' => ['approved', null], 'assessments' => [[29, 120]]],
        'f08' => ['name' => 'شركة المنوفية للتعبئة والتغليف', 'legal_name' => 'شركة المنوفية للتعبئة والتغليف', 'size' => 'small', 'sectors' => ['food'], 'governorate' => 'المنوفية', 'city' => 'مدينة السادات', 'address' => 'المنطقة الصناعية الرابعة، قطعة ١٨', 'contact_name' => 'رامي عطية', 'contact_job_title' => 'المدير العام', 'contact_phone' => '01000000108', 'registered' => 200, 'decision' => ['approved', null], 'assessments' => [[25, 110]]],
        'f09' => ['name' => 'مصنع دمياط للأثاث المعدني', 'legal_name' => 'شركة دمياط للأثاث المعدني', 'size' => 'small', 'sectors' => ['engineering_metal'], 'governorate' => 'دمياط', 'city' => 'دمياط الجديدة', 'address' => 'المنطقة الصناعية، قطعة ٣٣', 'contact_name' => 'سامح الدسوقي', 'contact_job_title' => 'مدير الإنتاج', 'contact_phone' => '01000000109', 'registered' => 190, 'decision' => ['approved', null], 'assessments' => [[22, 95]]],
        'f10' => ['name' => 'شركة بني سويف للمستلزمات الطبية', 'legal_name' => 'شركة بني سويف للمستلزمات الطبية', 'size' => 'small', 'sectors' => ['medical_pharmaceutical'], 'governorate' => 'بني سويف', 'city' => 'بني سويف الجديدة', 'address' => 'المنطقة الصناعية بكوم أبو راضي، قطعة ٥', 'contact_name' => 'هالة مرسي', 'contact_job_title' => 'مديرة الشؤون الفنية', 'contact_phone' => '01000000110', 'registered' => 182, 'decision' => ['approved', null], 'assessments' => [[12, 170], [18, 30]]],
        'f11' => ['name' => 'مصنع السويس للأسمدة والكيماويات', 'legal_name' => 'شركة السويس للأسمدة والكيماويات', 'size' => 'large', 'sectors' => ['chemical'], 'governorate' => 'السويس', 'city' => 'العين السخنة', 'address' => 'المنطقة الصناعية بالعين السخنة، قطعة ٢', 'contact_name' => 'مصطفى كامل', 'contact_job_title' => 'مدير المشروعات', 'contact_phone' => '01000000111', 'registered' => 60, 'decision' => ['approved', null], 'assessments' => []],
        'f12' => ['name' => 'شركة المنيا للصناعات الغذائية', 'legal_name' => 'شركة المنيا للصناعات الغذائية', 'size' => 'small', 'sectors' => ['food'], 'governorate' => 'المنيا', 'city' => 'المنيا الجديدة', 'address' => 'المنطقة الصناعية، قطعة ١٤', 'contact_name' => 'عبير جاد', 'contact_job_title' => 'مديرة الإدارة', 'contact_phone' => '01000000112', 'registered' => 9, 'decision' => null, 'assessments' => []],
        'f13' => ['name' => 'مصنع بورسعيد للصناعات الدوائية', 'legal_name' => 'شركة بورسعيد للصناعات الدوائية', 'size' => 'medium', 'sectors' => ['medical_pharmaceutical'], 'governorate' => 'بورسعيد', 'city' => 'بورسعيد', 'address' => 'المنطقة الصناعية جنوب الرسوة، قطعة ٨', 'contact_name' => 'شريف نصار', 'contact_job_title' => 'مدير التطوير', 'contact_phone' => '01000000113', 'registered' => 4, 'decision' => null, 'assessments' => []],
        'f14' => ['name' => 'ورش الغربية للتشغيل المعدني', 'legal_name' => 'شركة ورش الغربية للتشغيل المعدني', 'size' => 'small', 'sectors' => ['engineering_metal'], 'governorate' => 'الغربية', 'city' => 'طنطا', 'address' => 'طريق طنطا المحلة، المنطقة الحرفية', 'contact_name' => 'محمد الصاوي', 'contact_job_title' => 'صاحب المنشأة', 'contact_phone' => '01000000114', 'registered' => 35, 'decision' => ['changes_requested', 'يرجى استكمال عنوان المنشأة بالتفصيل وتحديث بيانات مسؤول التواصل.'], 'assessments' => [[17, 28]]],
        'f15' => ['name' => 'مصنع الدقهلية للزيوت النباتية', 'legal_name' => 'شركة الدقهلية للزيوت النباتية', 'size' => 'small', 'sectors' => ['food'], 'governorate' => 'الدقهلية', 'city' => 'المنصورة', 'address' => 'طريق المنصورة جمصة، الكيلو ٧', 'contact_name' => 'أسماء البدري', 'contact_job_title' => 'مديرة الحسابات', 'contact_phone' => '01000000115', 'registered' => 80, 'decision' => ['rejected', 'تعذر التحقق من بيانات السجل التجاري المقدمة، ويمكن إعادة التقديم ببيانات محدثة.'], 'assessments' => [[14, 70]]],
        'f16' => ['name' => 'شركة سوهاج للبلاستيك والكيماويات', 'legal_name' => 'شركة سوهاج للبلاستيك والكيماويات', 'size' => 'medium', 'sectors' => ['chemical'], 'governorate' => 'سوهاج', 'city' => 'سوهاج الجديدة', 'address' => 'المنطقة الصناعية بالكوثر، قطعة ١١', 'contact_name' => 'عادل فهمي', 'contact_job_title' => 'مدير المصنع', 'contact_phone' => '01000000116', 'registered' => 170, 'decision' => ['approved', null], 'suspended' => 'إيقاف مؤقت لحين تحديث بيانات الترخيص الصناعي المنتهية.', 'assessments' => [[10, 160]]],
    ];

    /**
     * Demo service requests from approved factories to eligible providers, each with the
     * steps taken on it. Step kinds: accept, decline, offer (provider); message (either
     * side); withdraw (the factory takes the request back from that provider), accept_offer,
     * cancel (factory); review_agreement (IMC); draft_contract (provider, once IMC approved).
     *
     * @var list<array{factory: string, service: string, providers: list<string>, title: string, need: string, requirements: string|null, day: int, steps: list<array<string, mixed>>}>
     */
    private const REQUESTS = [
        [
            'factory' => 'f01', 'service' => 'erp_business_applications.01', 'providers' => ['p01'], 'day' => 128,
            'title' => 'تطبيق نظام ERP لربط المبيعات والمخازن والإنتاج',
            'need' => 'نحتاج نظامًا موحدًا لتخطيط موارد المصنع يربط أوامر البيع بالمخازن وخطط الإنتاج، بدلًا من ملفات Excel المنفصلة بين الإدارات.',
            'requirements' => 'دعم اللغة العربية، وتقارير يومية للمخزون، وتدريب فريق المصنع على الاستخدام.',
            'steps' => [
                ['kind' => 'accept', 'provider' => 'p01', 'day' => 126],
                ['kind' => 'message', 'provider' => 'p01', 'side' => 'provider', 'day' => 125, 'body' => 'شكرًا لتواصلكم. نقترح زيارة ميدانية لحصر دورات العمل الحالية قبل تقديم العرض.'],
                ['kind' => 'message', 'provider' => 'p01', 'side' => 'factory', 'day' => 124, 'body' => 'مرحبًا بالزيارة يوم الأحد القادم، وسيكون مدير المخازن ومدير الإنتاج في الاستقبال.'],
                ['kind' => 'offer', 'provider' => 'p01', 'day' => 118, 'scope' => 'تحليل دورات العمل، وتهيئة وحدات المبيعات والمخازن والإنتاج، وترحيل البيانات الأساسية.', 'deliverables' => 'وثيقة تحليل المتطلبات، ونظام مهيأ في بيئة المصنع، وتقارير المخزون اليومية، وتدريب ١٥ مستخدمًا.', 'duration_days' => 150, 'price' => '1350000.00'],
                ['kind' => 'message', 'provider' => 'p01', 'side' => 'factory', 'day' => 116, 'body' => 'نرجو مراجعة السعر مع تقليص مدة التنفيذ إن أمكن، وإضافة وحدة المشتريات ضمن النطاق.'],
                ['kind' => 'offer', 'provider' => 'p01', 'day' => 112, 'scope' => 'تحليل دورات العمل، وتهيئة وحدات المبيعات والمخازن والمشتريات والإنتاج، وترحيل البيانات الأساسية.', 'deliverables' => 'وثيقة تحليل المتطلبات، ونظام مهيأ في بيئة المصنع، وتقارير المخزون اليومية، وتدريب ٢٠ مستخدمًا.', 'duration_days' => 130, 'price' => '1290000.00'],
                ['kind' => 'accept_offer', 'provider' => 'p01', 'day' => 109],
                ['kind' => 'review_agreement', 'provider' => 'p01', 'day' => 105, 'decision' => 'approved', 'reason' => null],
                ['kind' => 'draft_contract', 'provider' => 'p01', 'day' => 100, 'trainees' => 2, 'plan' => 'تدريب مهندسَين من المركز على إعداد وحدات النظام وتشغيلها خلال مرحلة التنفيذ.'],
            ],
        ],
        [
            'factory' => 'f02', 'service' => 'ot_ics_cybersecurity.02', 'providers' => ['p03'], 'day' => 64,
            'title' => 'تقييم جاهزية الأمن السيبراني لشبكة التحكم في خطوط الإنتاج',
            'need' => 'نرغب في تقييم مستوى الحماية الحالي لشبكات التشغيل والتحكم وتحديد الفجوات وأولويات المعالجة.',
            'requirements' => 'تنفيذ التقييم دون إيقاف خطوط الإنتاج.',
            'steps' => [
                ['kind' => 'accept', 'provider' => 'p03', 'day' => 63],
                ['kind' => 'message', 'provider' => 'p03', 'side' => 'provider', 'day' => 62, 'body' => 'يمكن تنفيذ التقييم على مرحلتين خلال فترات الصيانة المجدولة لتجنب أي توقف.'],
                ['kind' => 'offer', 'provider' => 'p03', 'day' => 58, 'scope' => 'حصر أصول شبكة التشغيل، وتقييم الإعدادات والصلاحيات، ومقارنة الوضع الحالي بأفضل الممارسات.', 'deliverables' => 'تقرير الفجوات مصنفًا حسب الخطورة، وخطة معالجة مرتبة الأولويات، وعرض تقديمي للإدارة.', 'duration_days' => 35, 'price' => '185000.00'],
                ['kind' => 'accept_offer', 'provider' => 'p03', 'day' => 55],
            ],
        ],
        [
            'factory' => 'f04', 'service' => 'cloud_infrastructure.01', 'providers' => ['p04'], 'day' => 92,
            'title' => 'نقل أنظمة إدارة الجودة إلى بنية سحابية',
            'need' => 'نحتاج نقل أنظمة إدارة الجودة والوثائق إلى بنية سحابية مع ضمان التوافر والنسخ الاحتياطي.',
            'requirements' => 'استضافة البيانات داخل مصر، وصلاحيات وصول حسب الإدارات.',
            'steps' => [
                ['kind' => 'accept', 'provider' => 'p04', 'day' => 90],
                ['kind' => 'offer', 'provider' => 'p04', 'day' => 85, 'scope' => 'تصميم البنية السحابية، ونقل نظم الجودة والوثائق، وإعداد النسخ الاحتياطي والمراقبة.', 'deliverables' => 'بنية سحابية مهيأة، وخطة نقل منفذة، ووثائق التشغيل، وتدريب فريق تكنولوجيا المعلومات.', 'duration_days' => 60, 'price' => '420000.00'],
                ['kind' => 'accept_offer', 'provider' => 'p04', 'day' => 80],
                ['kind' => 'review_agreement', 'provider' => 'p04', 'day' => 76, 'decision' => 'rejected', 'reason' => 'يلزم توضيح نطاق حماية البيانات ومكان استضافتها في العرض قبل اعتماد الاتفاق.'],
            ],
        ],
        [
            'factory' => 'f03', 'service' => 'automation_ot.01', 'providers' => ['p02'], 'day' => 40,
            'title' => 'تطبيق نظام تنفيذ التصنيع (MES) لخط الدرفلة',
            'need' => 'نحتاج متابعة لحظية لأوامر التشغيل ونسب الهالك وأزمنة التوقف في خط الدرفلة.',
            'requirements' => 'ربط النظام بماكينات الخط الحالية وتقارير لكل وردية.',
            'steps' => [
                ['kind' => 'accept', 'provider' => 'p02', 'day' => 39],
                ['kind' => 'message', 'provider' => 'p02', 'side' => 'factory', 'day' => 38, 'body' => 'نرفق لكم قائمة الماكينات الحالية وأنظمة التحكم المستخدمة بها.'],
                ['kind' => 'offer', 'provider' => 'p02', 'day' => 33, 'scope' => 'تركيب وحدات جمع البيانات، وتهيئة نظام تنفيذ التصنيع لخط الدرفلة، ولوحات متابعة الورديات.', 'deliverables' => 'نظام MES مهيأ، ولوحات متابعة لحظية، وتقارير الهالك والتوقف، وتدريب المشرفين.', 'duration_days' => 90, 'price' => '960000.00'],
                ['kind' => 'accept_offer', 'provider' => 'p02', 'day' => 29],
            ],
        ],
        [
            'factory' => 'f05', 'service' => 'erp_business_applications.03', 'providers' => ['p01'], 'day' => 6,
            'title' => 'أتمتة دورة اعتماد طلبات الشراء',
            'need' => 'تمر طلبات الشراء حاليًا بأربع توقيعات ورقية، ونرغب في دورة اعتماد إلكترونية بمسارات واضحة.',
            'requirements' => null,
            'steps' => [],
        ],
        [
            'factory' => 'f07', 'service' => 'ai_data_analytics.03', 'providers' => ['p05'], 'day' => 25,
            'title' => 'فحص بصري آلي لعبوات المنتجات على خط التعبئة',
            'need' => 'نرغب في اكتشاف عيوب الإغلاق والملصقات تلقائيًا على خط التعبئة بدلًا من الفحص اليدوي بالعينة.',
            'requirements' => 'سرعة الخط ١٢٠ عبوة في الدقيقة.',
            'steps' => [
                ['kind' => 'accept', 'provider' => 'p05', 'day' => 24],
                ['kind' => 'message', 'provider' => 'p05', 'side' => 'provider', 'day' => 23, 'body' => 'نحتاج عينات مصورة للعيوب الشائعة لتقدير دقة النموذج المتوقعة.'],
                ['kind' => 'message', 'provider' => 'p05', 'side' => 'factory', 'day' => 21, 'body' => 'تم تجهيز مجموعة صور لعيوب الإغلاق والملصقات وسنرسلها عبر مسؤول الجودة.'],
                ['kind' => 'offer', 'provider' => 'p05', 'day' => 12, 'scope' => 'تركيب كاميرات الفحص، وتدريب نموذج رؤية حاسوبية على عيوب العبوات، وربط الإنذارات بخط التعبئة.', 'deliverables' => 'منظومة فحص بصري تعمل على الخط، ولوحة متابعة للعيوب، وتقرير دقة النموذج، وتدريب فريق الجودة.', 'duration_days' => 75, 'price' => '610000.00'],
            ],
        ],
        [
            'factory' => 'f06', 'service' => 'ot_ics_cybersecurity.03', 'providers' => ['p03'], 'day' => 18,
            'title' => 'اختبار اختراق لأنظمة التحكم في ورشة اللحام الآلي',
            'need' => 'نرغب في اختبار اختراق لأنظمة التحكم المرتبطة بخلايا اللحام الآلي بعد ربطها بالشبكة الإدارية.',
            'requirements' => null,
            'steps' => [
                ['kind' => 'decline', 'provider' => 'p03', 'day' => 16, 'reason' => 'لا تتوفر لدينا سعة لتنفيذ أعمال جديدة خلال الشهرين القادمين.'],
            ],
        ],
        [
            'factory' => 'f09', 'service' => 'automation_ot.03', 'providers' => ['p02'], 'day' => 70,
            'title' => 'حساسات إنترنت الأشياء لمراقبة استهلاك الطاقة',
            'need' => 'نرغب في قياس استهلاك الطاقة لكل ماكينة لتحديد مصادر الهدر.',
            'requirements' => null,
            'steps' => [
                ['kind' => 'accept', 'provider' => 'p02', 'day' => 68],
                ['kind' => 'message', 'provider' => 'p02', 'side' => 'provider', 'day' => 67, 'body' => 'نقترح البدء بخمس ماكينات كمرحلة تجريبية.'],
                ['kind' => 'cancel', 'day' => 60, 'reason' => 'تم تأجيل المشروع إلى الموازنة القادمة.'],
            ],
        ],
        [
            'factory' => 'f08', 'service' => 'cloud_infrastructure.03', 'providers' => ['p04'], 'day' => 50,
            'title' => 'تنظيم تخزين بيانات الإنتاج والجودة',
            'need' => 'بيانات الإنتاج والجودة موزعة على أجهزة متعددة، ونرغب في تخزينها مركزيًا مع صلاحيات وصول.',
            'requirements' => null,
            'steps' => [
                ['kind' => 'accept', 'provider' => 'p04', 'day' => 49],
                ['kind' => 'message', 'provider' => 'p04', 'side' => 'provider', 'day' => 47, 'body' => 'بعد الزيارة نقترح البدء بربط أجهزة خطي الإنتاج الأول والثاني فقط.'],
                ['kind' => 'withdraw', 'provider' => 'p04', 'day' => 44, 'reason' => 'قررنا تنفيذ المرحلة الأولى داخليًا بعد مراجعة المتطلبات.'],
            ],
        ],
        [
            'factory' => 'f10', 'service' => 'ai_data_analytics.02', 'providers' => ['p05'], 'day' => 10,
            'title' => 'صيانة تنبؤية لماكينات التعقيم',
            'need' => 'تتكرر أعطال ماكينات التعقيم المفاجئة، ونرغب في التنبؤ بالأعطال من بيانات الحرارة والاهتزاز.',
            'requirements' => 'الالتزام بمتطلبات الجودة الدوائية في أي تعديل على الماكينات.',
            'steps' => [
                ['kind' => 'accept', 'provider' => 'p05', 'day' => 9],
                ['kind' => 'message', 'provider' => 'p05', 'side' => 'provider', 'day' => 8, 'body' => 'هل تتوفر سجلات أعطال سابقة للماكينات خلال العام الماضي؟'],
                ['kind' => 'message', 'provider' => 'p05', 'side' => 'factory', 'day' => 7, 'body' => 'نعم، تتوفر سجلات الصيانة الورقية لعامين، ويجري تجميعها في ملف واحد.'],
            ],
        ],
    ];

    /**
     * Promotions IMC sets on approved listings: [provider, service, headline, priority,
     * created (days ago), starts (days from creation), ends (days from creation), ended
     * by IMC (days ago) or null].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int|null}>
     */
    private const PROMOTIONS = [
        ['p01', 'erp_business_applications.01', 'حلول ERP متكاملة لمصانع الأغذية والكيماويات', 50, 12, 0, 40, null],
        ['p05', 'ai_data_analytics.03', 'الفحص البصري الذكي لخطوط الإنتاج', 40, 3, 8, 45, null],
        ['p02', 'automation_ot.02', 'أنظمة SCADA لمراقبة خطوط الإنتاج', 30, 70, 0, 60, 35],
    ];

    /**
     * Landing-page announcements: [title, description, colour, badge, link, tags, created
     * (days ago), starts (days from creation) or null, ends (days from creation) or null,
     * published].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string|null, 5: list<string>, 6: int, 7: int|null, 8: int|null, 9: bool}>
     */
    private const ANNOUNCEMENTS = [
        ['سجّل مصنعك وابدأ تقييم الجاهزية الرقمية', 'سجّل منشأتك على منصة جاهز، وأجب عن عشرة أسئلة لتعرف مستوى جاهزيتك الرقمية والخدمات الموصى بها لمرحلتك.', 'green', 'التسجيل مفتوح', '/register/factory', ['تقييم الجاهزية', 'تسجيل المصانع'], 30, null, null, true],
        ['ورشة عمل: خارطة الطريق للتحول الرقمي في المصانع', 'ورشة تعريفية لممثلي المصانع حول قراءة نتيجة تقييم الجاهزية واختيار أولويات التحول الرقمي.', 'blue', 'ورشة عمل', '/register/factory', ['ورش عمل', 'خارطة الطريق'], 5, 0, 30, true],
        ['ملتقى مزودي خدمات التحول الرقمي', 'لقاء تعريفي لمزودي الخدمات حول خطوات التسجيل ومراجعة الخدمات على المنصة.', 'orange', 'لقاء تعريفي', '/register/provider', ['مزودو الخدمات'], 75, 0, 30, true],
        ['برنامج تدريبي في الأمن السيبراني الصناعي', 'مسودة إعلان عن برنامج تدريبي لفرق تكنولوجيا المعلومات والتشغيل في المصانع، قيد المراجعة قبل النشر.', 'purple', 'قريبًا', null, ['الأمن السيبراني', 'تدريب'], 2, null, null, false],
    ];

    /**
     * Legal change requests: [organization key, field, new value, note, days ago, IMC
     * decision (null: pending), decision reason, decided (days ago)].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: string|null, 6: string|null, 7: int|null}>
     */
    private const CHANGE_REQUESTS = [
        ['f04', 'legal_name', 'شركة النيل للأدوية والمستحضرات الطبية (شركة مساهمة مصرية)', 'تعديل الشكل القانوني بعد تحويل الشركة إلى شركة مساهمة.', 8, null, null, null],
        ['f07', 'commercial_registration_number', 'DEMO-CR-F07-2', 'تحديث رقم السجل التجاري بعد تجديد القيد.', 48, 'approve', null, 45],
        ['p02', 'legal_name', 'شركة دلتا للأتمتة والتحكم والتكامل الصناعي', 'تعديل الاسم القانوني بعد إضافة نشاط التكامل الصناعي.', 5, null, null, null],
        ['p04', 'tax_registration_number', 'DEMO-TX-P04-2', 'تحديث رقم التسجيل الضريبي.', 20, 'reject', 'الرقم المقدم لا يطابق البطاقة الضريبية المسجلة؛ يرجى إعادة التقديم مع البطاقة المحدثة.', 17],
    ];

    private CarbonImmutable $origin;

    private User $reviewer;

    /**
     * @var array<string, ServiceProvider>
     */
    private array $providers = [];

    /**
     * @var array<string, Factory>
     */
    private array $factories = [];

    /**
     * @var array<string, User>
     */
    private array $members = [];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command->warn('DemoDataSeeder only runs in the local and testing environments; nothing was created.');

            return;
        }

        $this->call(ReferenceDataSeeder::class);

        $previousNow = Carbon::hasTestNow() ? Carbon::getTestNow() : null;
        $mailSetting = config('jahez.notifications.mail');
        $rateLimit = config('api.rate_limit_per_minute');
        // In-app notifications are stored as in production; no demo email is queued.
        config(['jahez.notifications.mail' => false]);
        // The clock moves back and forth between the scripted steps, so the generic per-user
        // abuse limit would count months of activity as one minute. Only it is lifted, and
        // only for this process; the negotiation limits stay.
        config(['api.rate_limit_per_minute' => 100000]);
        $this->origin = CarbonImmutable::now()->startOfMinute();

        try {
            $this->reviewer = $this->reviewer();

            foreach (self::PROVIDERS as $key => $provider) {
                $this->providers[$key] = $this->provider($key, $provider);
            }
            foreach (self::FACTORIES as $key => $factory) {
                $this->factories[$key] = $this->factory($key, $factory);
            }
            $this->levelServices();
            foreach (self::REQUESTS as $serviceRequest) {
                $this->serviceRequest($serviceRequest);
            }
            foreach (self::PROMOTIONS as $promotion) {
                $this->promotion(...$promotion);
            }
            foreach (self::ANNOUNCEMENTS as $announcement) {
                $this->announcement(...$announcement);
            }
            foreach (self::CHANGE_REQUESTS as $changeRequest) {
                $this->changeRequest(...$changeRequest);
            }
        } finally {
            Carbon::setTestNow($previousNow);
            config(['jahez.notifications.mail' => $mailSetting, 'api.rate_limit_per_minute' => $rateLimit]);
            Auth::forgetGuards();
        }

        $this->command->info('Demo data is in place: '.count($this->factories).' factories, '.count($this->providers).' providers. Accounts use the domain '.self::EMAIL_DOMAIN.'.');
    }

    /**
     * The demo IMC reviewer who takes every IMC decision below.
     */
    private function reviewer(): User
    {
        $existing = User::query()->where('email', self::REVIEWER['email'])->first();
        if ($existing !== null) {
            return $existing;
        }

        $this->at(265);

        return $this->createUser(self::REVIEWER['email'], self::REVIEWER['name'], Role::ImcAdmin);
    }

    /**
     * @param  array{name: string, legal_name: string, description: string, representative_name: string, job_title: string, phone: string, dx_experience_years: int, governorate: string, city: string, address: string, sectors: list<string>, listings: array<string, string|null>, registered: int, decision: array{0: string, 1: string|null}|null}  $data
     */
    private function provider(string $key, array $data): ServiceProvider
    {
        $email = "{$key}@".self::EMAIL_DOMAIN;
        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null) {
            $this->members[$key] = $existing;

            return ServiceProvider::query()->findOrFail($existing->service_provider_id);
        }

        return DB::transaction(function () use ($key, $email, $data): ServiceProvider {
            $this->at($data['registered']);

            // As the registration job registers a provider (RegisterOrganization).
            $provider = new ServiceProvider;
            $provider->fill([
                ...array_intersect_key($data, array_flip(['name', 'legal_name', 'description', 'representative_name', 'job_title', 'phone', 'dx_experience_years', 'governorate', 'city', 'address'])),
                'email' => $email,
                'commercial_registration_number' => 'DEMO-CR-'.strtoupper($key),
                'tax_registration_number' => 'DEMO-TX-'.strtoupper($key),
            ]);
            $provider->save();
            $provider->sectors()->sync($this->sectorIds($data['sectors']));
            $provider->services()->sync($this->serviceIds(array_keys($data['listings']))->values()->all());
            $member = $this->createUser($email, $data['representative_name'], Role::ProviderMember, serviceProviderId: $provider->id);
            $this->members[$key] = $member;
            AuditLog::record(AuditEvent::ServiceProviderRegistered, $member, $provider, [
                'registration_id' => "demo-{$key}",
                'name' => $provider->name,
                'sectors' => $data['sectors'],
                'services' => array_keys($data['listings']),
                'documents' => [],
            ], '127.0.0.1');
            MarketplaceNotifications::organizationRegistered('service_provider', $provider->id, $provider->name);

            // IMC reviews the account, then each listing.
            if ($data['decision'] !== null) {
                $this->at($data['registered'] - 3);
                $this->api($this->reviewer, 'POST', "service-providers/{$provider->id}/approval", ['decision' => $data['decision'][0], 'reason' => $data['decision'][1]]);
            }
            $serviceIds = $this->serviceIds(array_keys($data['listings']));
            foreach ($data['listings'] as $code => $decision) {
                if ($decision === null) {
                    continue;
                }
                $this->at($data['registered'] - 4);
                $path = "service-providers/{$provider->id}/services/{$serviceIds[$code]}/review";
                $reason = self::LISTING_REASONS["{$key}:{$code}"] ?? null;
                if ($decision === 'suspended') {
                    $this->api($this->reviewer, 'POST', $path, ['decision' => 'approved']);
                    $this->at(intdiv($data['registered'], 3));
                }
                $this->api($this->reviewer, 'POST', $path, ['decision' => $decision, 'reason' => $reason]);
            }

            return $provider;
        });
    }

    /**
     * @param  array{name: string, legal_name: string, size: string, sectors: list<string>, governorate: string, city: string, address: string, contact_name: string, contact_job_title: string, contact_phone: string, registered: int, decision: array{0: string, 1: string|null}|null, suspended?: string, assessments: list<array{0: int, 1: int}>}  $data
     */
    private function factory(string $key, array $data): Factory
    {
        $email = "{$key}@".self::EMAIL_DOMAIN;
        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null) {
            $this->members[$key] = $existing;

            return Factory::query()->findOrFail($existing->factory_id);
        }

        return DB::transaction(function () use ($key, $email, $data): Factory {
            $this->at($data['registered']);

            // As the registration job registers a factory (RegisterOrganization).
            $factory = new Factory;
            $factory->fill([
                ...array_intersect_key($data, array_flip(['name', 'legal_name', 'size', 'governorate', 'city', 'address', 'contact_name', 'contact_job_title', 'contact_phone'])),
                'contact_email' => $email,
                'commercial_registration_number' => 'DEMO-CR-'.strtoupper($key),
                'tax_registration_number' => 'DEMO-TX-'.strtoupper($key),
            ]);
            $factory->save();
            $factory->sectors()->sync($this->sectorIds($data['sectors']));
            $member = $this->createUser($email, $data['contact_name'], Role::FactoryMember, factoryId: $factory->id);
            $this->members[$key] = $member;
            AuditLog::record(AuditEvent::FactoryRegistered, $member, $factory, [
                'registration_id' => "demo-{$key}",
                'name' => $factory->name,
                'sectors' => $data['sectors'],
                'documents' => [],
            ], '127.0.0.1');
            MarketplaceNotifications::organizationRegistered('factory', $factory->id, $factory->name);

            if ($data['decision'] !== null) {
                $this->at($data['registered'] - 3);
                $this->api($this->reviewer, 'POST', "factories/{$factory->id}/approval", ['decision' => $data['decision'][0], 'reason' => $data['decision'][1]]);
            }

            foreach ($data['assessments'] as $number => [$total, $daysAgo]) {
                $this->at($daysAgo);
                $this->api($member, 'POST', "factories/{$factory->id}/readiness-assessments", $this->readinessAnswers($total, crc32("{$key}-{$number}")), ['Idempotency-Key' => "demo-{$key}-{$number}"]);
            }

            if (isset($data['suspended'])) {
                $this->at(12);
                $this->api($this->reviewer, 'POST', "factories/{$factory->id}/approval", ['decision' => 'suspended', 'reason' => $data['suspended']]);
            }

            return $factory;
        });
    }

    /**
     * A submission of the current questionnaire whose answers add up to the total: each
     * question gets the choice worth the points chosen for it. The spread over the
     * questions is deterministic (seeded) and varied, as real answers are; the server
     * scores and classifies the submission.
     *
     * @return array{questionnaire_version: int, answers: list<array{question_id: int, choice_id: int}>}
     */
    private function readinessAnswers(int $total, int $seed): array
    {
        $questionnaire = ReadinessQuestionnaire::query()->where('is_current', true)->with('questions.choices')->firstOrFail();
        $questions = $questionnaire->questions->values();
        $count = $questions->count();

        $points = array_fill(0, $count, intdiv($total, $count));
        $order = (new Randomizer(new Mt19937($seed)))->shuffleArray(range(0, $count - 1));
        for ($i = 0; $i < $total % $count; $i++) {
            $points[$order[$i]]++;
        }
        // Move a few points between questions so no two factories answer alike.
        for ($i = 0; $i < 3; $i++) {
            [$from, $to] = [$order[(2 * $i + 5) % $count], $order[(2 * $i + 6) % $count]];
            if ($points[$from] > 1 && $points[$to] < 4) {
                $points[$from]--;
                $points[$to]++;
            }
        }

        return [
            'questionnaire_version' => $questionnaire->version,
            'answers' => array_values($questions->map(function (ReadinessQuestion $question, int $index) use ($points): array {
                $choice = $question->choices->firstWhere('points', $points[$index])
                    ?? throw new RuntimeException("Question {$question->number} has no choice worth {$points[$index]} points.");

                return ['question_id' => $question->id, 'choice_id' => $choice->id];
            })->all()),
        ];
    }

    /**
     * @param  array{factory: string, service: string, providers: list<string>, title: string, need: string, requirements: string|null, day: int, steps: list<array<string, mixed>>}  $data
     */
    private function serviceRequest(array $data): void
    {
        $factory = $this->factories[$data['factory']];
        if (ServiceRequest::query()->where('factory_id', $factory->id)->where('title', $data['title'])->exists()) {
            return;
        }

        DB::transaction(function () use ($data): void {
            $member = $this->members[$data['factory']];
            $this->at($data['day']);
            $created = $this->api($member, 'POST', 'service-requests', [
                'service' => $data['service'],
                'title' => $data['title'],
                'need' => $data['need'],
                'requirements' => $data['requirements'],
                'provider_ids' => array_map(fn (string $key): int => $this->providers[$key]->id, $data['providers']),
            ]);
            $serviceRequestId = (int) $created['id'];

            foreach ($data['steps'] as $step) {
                $this->at((int) $step['day']);
                $providerKey = isset($step['provider']) ? (string) $step['provider'] : null;
                $threadId = $providerKey === null ? 0 : (int) ProviderRequest::query()
                    ->where('service_request_id', $serviceRequestId)
                    ->where('service_provider_id', $this->providers[$providerKey]->id)
                    ->value('id');
                $providerMember = $providerKey === null ? null : $this->members[$providerKey];

                match ($step['kind']) {
                    'accept' => $this->api($providerMember, 'POST', "provider-requests/{$threadId}/accept"),
                    'decline' => $this->api($providerMember, 'POST', "provider-requests/{$threadId}/decline", ['reason' => $step['reason']]),
                    'withdraw' => $this->api($member, 'POST', "provider-requests/{$threadId}/withdraw", ['reason' => $step['reason']]),
                    'message' => $this->api($step['side'] === 'provider' ? $providerMember : $member, 'POST', "provider-requests/{$threadId}/messages", ['body' => $step['body']]),
                    'offer' => $this->api($providerMember, 'POST', "provider-requests/{$threadId}/offers", [
                        'based_on_version' => Offer::query()->where('provider_request_id', $threadId)->max('version'),
                        'scope' => $step['scope'],
                        'deliverables' => $step['deliverables'],
                        'duration_days' => $step['duration_days'],
                        'valid_until' => now()->addDays(30)->toDateString(),
                        'price' => ['amount' => $step['price'], 'currency' => Offer::CURRENCY],
                    ]),
                    'accept_offer' => $this->api($member, 'POST', "provider-requests/{$threadId}/offers/".Offer::query()->where('provider_request_id', $threadId)->orderByDesc('version')->value('id').'/accept'),
                    'review_agreement' => $this->api($this->reviewer, 'POST', 'agreements/'.Agreement::query()->where('provider_request_id', $threadId)->value('id').'/review', ['decision' => $step['decision'], 'reason' => $step['reason']]),
                    'cancel' => $this->api($member, 'POST', "service-requests/{$serviceRequestId}/cancel", ['reason' => $step['reason']]),
                    'draft_contract' => $this->api($providerMember, 'POST', 'agreements/'.Agreement::query()->where('provider_request_id', $threadId)->value('id').'/contracts', ['knowledge_transfer' => ['trainees' => $step['trainees'], 'training_plan' => $step['plan']]]),
                    default => throw new RuntimeException("Unknown demo step {$step['kind']}."),
                };
            }
        });
    }

    /**
     * Demo choices only (ADR-025): the services each readiness level makes available, set
     * by the IMC reviewer through the API. A repeated call changes nothing.
     */
    private function levelServices(): void
    {
        $pairs = [];

        foreach (ReadinessQuestionnaire::query()->where('is_current', true)->sole()->categories()->with('recommendations.services')->get() as $category) {
            foreach ($category->recommendations as $recommendation) {
                foreach ($recommendation->services as $service) {
                    $pairs["{$category->code->value}/services/{$service->id}"] = true;
                }
            }
        }

        foreach (self::REQUESTS as $request) {
            $level = $this->factories[$request['factory']]->currentReadinessAssessment()->with('category')->first()?->category?->code->value;

            if ($level !== null) {
                $pairs[$level.'/services/'.CatalogService::query()->where('code', $request['service'])->value('id')] = true;
            }
        }

        $this->at(250);

        foreach (array_keys($pairs) as $pair) {
            $this->api($this->reviewer, 'PUT', "readiness-levels/{$pair}", ['is_active' => true]);
        }
    }

    private function promotion(string $providerKey, string $service, string $headline, int $priority, int $createdDaysAgo, int $startsAfter, int $endsAfter, ?int $endedDaysAgo): void
    {
        $provider = $this->providers[$providerKey];
        $serviceId = $this->serviceIds([$service])[$service];
        if (ServicePromotion::query()->where('service_provider_id', $provider->id)->where('catalog_service_id', $serviceId)->where('headline', $headline)->exists()) {
            return;
        }

        DB::transaction(function () use ($provider, $service, $headline, $priority, $createdDaysAgo, $startsAfter, $endsAfter, $endedDaysAgo): void {
            $this->at($createdDaysAgo);
            $promotion = $this->api($this->reviewer, 'POST', 'promotions', [
                'service_provider_id' => $provider->id,
                'service' => $service,
                'headline' => $headline,
                'priority' => $priority,
                'starts_at' => now()->addDays($startsAfter)->toIso8601ZuluString(),
                'ends_at' => now()->addDays($endsAfter)->toIso8601ZuluString(),
            ]);
            if ($endedDaysAgo !== null) {
                $this->at($endedDaysAgo);
                $this->api($this->reviewer, 'POST', "promotions/{$promotion['id']}/end");
            }
        });
    }

    /**
     * @param  list<string>  $tags
     */
    private function announcement(string $title, string $description, string $color, string $badge, ?string $link, array $tags, int $createdDaysAgo, ?int $startsAfter, ?int $endsAfter, bool $published): void
    {
        if (PublicAnnouncement::query()->where('title', $title)->exists()) {
            return;
        }

        DB::transaction(function () use ($title, $description, $color, $badge, $link, $tags, $createdDaysAgo, $startsAfter, $endsAfter, $published): void {
            $this->at($createdDaysAgo);
            $announcement = $this->api($this->reviewer, 'POST', 'announcements', [
                'title' => $title,
                'description' => $description,
                'color' => $color,
                'badge_text' => $badge,
                'link_path' => $link,
                'tags' => $tags,
                'starts_at' => $startsAfter === null ? null : now()->addDays($startsAfter)->toIso8601ZuluString(),
                'ends_at' => $endsAfter === null ? null : now()->addDays($endsAfter)->toIso8601ZuluString(),
            ]);
            if ($published) {
                $this->api($this->reviewer, 'POST', "announcements/{$announcement['id']}/publish");
            }
        });
    }

    private function changeRequest(string $organizationKey, string $field, string $value, string $note, int $daysAgo, ?string $decision, ?string $reason, ?int $decidedDaysAgo): void
    {
        $isFactory = isset($this->factories[$organizationKey]);
        $organization = $isFactory ? $this->factories[$organizationKey] : $this->providers[$organizationKey];
        $exists = $isFactory
            ? FactoryProfileChangeRequest::query()->where('factory_id', $organization->id)->where('note', $note)->exists()
            : ProviderProfileChangeRequest::query()->where('service_provider_id', $organization->id)->where('note', $note)->exists();
        if ($exists) {
            return;
        }

        DB::transaction(function () use ($isFactory, $organization, $organizationKey, $field, $value, $note, $daysAgo, $decision, $reason, $decidedDaysAgo): void {
            $base = ($isFactory ? 'factories/' : 'service-providers/').$organization->id.'/change-requests';
            $this->at($daysAgo);
            $changeRequest = $this->api($this->members[$organizationKey], 'POST', $base, [$field => $value, 'note' => $note]);
            if ($decision !== null) {
                $this->at((int) $decidedDaysAgo);
                $this->api($this->reviewer, 'POST', "{$base}/{$changeRequest['id']}/{$decision}", $reason === null ? [] : ['reason' => $reason]);
            }
        });
    }

    /**
     * Sends one request through the application's HTTP kernel as the given user, exactly
     * as the web client would (validation, policies, transactions, audit, notifications),
     * and returns the response's `data`. Any refusal stops the seeder.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function api(?User $actor, string $method, string $path, array $payload = [], array $headers = []): array
    {
        if ($actor === null) {
            throw new RuntimeException("Demo step {$method} {$path} has no acting user.");
        }

        Auth::guard('sanctum')->setUser($actor);
        Auth::shouldUse('sanctum');

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create('/api/v1/'.$path, $method, server: $server, content: json_encode($payload, JSON_THROW_ON_ERROR));
        $response = app(HttpKernel::class)->handle($request);
        $content = (string) $response->getContent();

        if ($response->getStatusCode() >= 300) {
            throw new RuntimeException("Demo step {$method} /api/v1/{$path} as {$actor->email} was refused ({$response->getStatusCode()}): ".mb_substr($content, 0, 800));
        }

        $body = json_decode($content, true);

        return is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /**
     * Moves the clock to the given number of days before the seeding started, keeping
     * the steps of one day in order.
     */
    private function at(int $daysAgo): void
    {
        static $step = 0;
        $step++;

        Carbon::setTestNow($this->origin->subDays(max(1, $daysAgo))->setTime(9, 0)->addMinutes($step % 480));
    }

    private function createUser(string $email, string $name, Role $role, ?int $factoryId = null, ?int $serviceProviderId = null): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => LocalDemoSeeder::PASSWORD]);
        $user->role = $role;
        $user->factory_id = $factoryId;
        $user->service_provider_id = $serviceProviderId;
        $user->email_verified_at = now();
        $user->email_notifications = false;
        $user->save();

        return $user;
    }

    /**
     * @param  list<string>  $codes
     * @return list<int>
     */
    private function sectorIds(array $codes): array
    {
        $ids = array_values(Sector::query()->whereIn('code', $codes)->get()->map(fn (Sector $sector): int => $sector->id)->all());
        if (count($ids) !== count($codes)) {
            throw new RuntimeException('A demo sector code is not in the reference data: '.implode(', ', $codes));
        }

        return $ids;
    }

    /**
     * Catalog service ids by code; every code must exist (the catalog is never extended).
     *
     * @param  list<string>  $codes
     * @return Collection<string, int>
     */
    private function serviceIds(array $codes): Collection
    {
        $ids = CatalogService::query()->whereIn('code', $codes)->pluck('id', 'code');
        if ($ids->count() !== count($codes)) {
            throw new RuntimeException('A demo service code is not in the catalog: '.implode(', ', array_diff($codes, $ids->keys()->all())));
        }

        return $ids;
    }
}
