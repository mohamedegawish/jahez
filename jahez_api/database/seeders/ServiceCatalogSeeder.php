<?php

namespace Database\Seeders;

use App\Models\CatalogService;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

/**
 * The service catalog. Source: services workbook "Copy of الخدمات التحول الرقمي.xlsx",
 * Sheet1, section "هيكلية خدمات التحول الرقمي والتصنيع الذكي (Industry 4.0)" (A11), rows
 * 12–61 (ADR-014).
 *
 * - Category names: the Arabic text of the cell without its list number ("2.", "3. "…).
 *   name_en is the English the workbook prints in parentheses after it.
 * - Service names: the cell text with surrounding whitespace trimmed, nothing else changed.
 * - Rows 59–60 are one service, as the owner decided on 2026-10-03: row 60 begins with
 *   «و» ("and") and continues row 59.
 *
 * tests/Feature/Database/ServiceCatalogSourceTest.php checks every row against the
 * workbook file itself. Codes are stable identifiers: "<category>.<two-digit position>".
 */
class ServiceCatalogSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name_ar: string, name_en: string, source_ref: string, services: list<array{name_ar: string, source_ref: string}>}>
     */
    public const CATEGORIES = [
        [
            'code' => 'erp_business_applications',
            'name_ar' => 'نظم تخطيط وإدارة موارد المؤسسات والتطبيقات الرقمية',
            'name_en' => 'ERP & Business Applications',
            'source_ref' => 'Sheet1!C12',
            'services' => [
                ['name_ar' => 'نظم تخطيط وإدارة موارد المؤسسات (ERP).', 'source_ref' => 'Sheet1!C13'],
                ['name_ar' => 'رقمنة وأتمتة العمليات والإجراءات الإدارية والتشغيلية.', 'source_ref' => 'Sheet1!C14'],
                ['name_ar' => 'إدارة سير العمل والإجراءات (Workflow Management).', 'source_ref' => 'Sheet1!C15'],
                ['name_ar' => 'إعادة هندسة ورقمنة العمليات.', 'source_ref' => 'Sheet1!C16'],
                ['name_ar' => 'ربط وتكامل الأنظمة والتطبيقات.', 'source_ref' => 'Sheet1!C17'],
            ],
        ],
        [
            'code' => 'automation_ot',
            'name_ar' => 'الأتمتة والنظم التشغيلية الصناعية',
            'name_en' => 'Automation & OT',
            'source_ref' => 'Sheet1!C18',
            'services' => [
                ['name_ar' => 'نظم تنفيذ التصنيع (MES).', 'source_ref' => 'Sheet1!C19'],
                ['name_ar' => 'نظم المراقبة والتحكم الصناعي (SCADA).', 'source_ref' => 'Sheet1!C20'],
                ['name_ar' => 'إنترنت الأشياء الصناعي (IIoT).', 'source_ref' => 'Sheet1!C21'],
                ['name_ar' => 'ربط الآلات وخطوط الإنتاج وجمع بيانات التشغيل.', 'source_ref' => 'Sheet1!C22'],
                ['name_ar' => 'دمج الروبوتات والروبوتات التعاونية (Cobots) لرفع كفاءة خطوط الإنتاج.', 'source_ref' => 'Sheet1!C23'],
                ['name_ar' => 'نظم إدارة الطاقة والمرافق (EMS).', 'source_ref' => 'Sheet1!C24'],
                ['name_ar' => 'نظم إدارة المخازن والمستودعات (WMS).', 'source_ref' => 'Sheet1!C25'],
                ['name_ar' => 'التكامل بين أنظمة التشغيل والمعلومات (OT/IT) وربطها بأنظمة ERP وMES.', 'source_ref' => 'Sheet1!C26'],
            ],
        ],
        [
            'code' => 'cloud_infrastructure',
            'name_ar' => 'النظم السحابية والبنية التحتية الرقمية',
            'name_en' => 'Cloud & Infrastructure',
            'source_ref' => 'Sheet1!C27',
            'services' => [
                ['name_ar' => 'الحلول السحابية والبنية السحابية', 'source_ref' => 'Sheet1!C28'],
                ['name_ar' => 'البنية التحتية الرقمية ومراكز البيانات.', 'source_ref' => 'Sheet1!C29'],
                ['name_ar' => 'تخزين وإدارة البيانات.', 'source_ref' => 'Sheet1!C30'],
                ['name_ar' => 'حلول النسخ الاحتياطي واستعادة البيانات.', 'source_ref' => 'Sheet1!C31'],
                ['name_ar' => 'تكامل الأنظمة والمنصات الرقمية.', 'source_ref' => 'Sheet1!C32'],
            ],
        ],
        [
            'code' => 'ot_ics_cybersecurity',
            'name_ar' => 'الأمن السيبراني الصناعي',
            'name_en' => 'OT/ICS Cybersecurity',
            'source_ref' => 'Sheet1!C33',
            'services' => [
                ['name_ar' => 'تأمين شبكات وأنظمة التشغيل والتحكم الصناعي.', 'source_ref' => 'Sheet1!C34'],
                ['name_ar' => 'تقييم الجاهزية والفجوات في الأمن السيبراني.', 'source_ref' => 'Sheet1!C35'],
                ['name_ar' => 'تقييم الفجوات واختبارات الاختراق.', 'source_ref' => 'Sheet1!C36'],
                ['name_ar' => 'الحماية من هجمات والتهديدات السيبرانية.', 'source_ref' => 'Sheet1!C37'],
                ['name_ar' => 'حماية استمرارية العمليات التشغيلية.', 'source_ref' => 'Sheet1!C38'],
                ['name_ar' => 'حوكمة وإدارة الأمن السيبراني.', 'source_ref' => 'Sheet1!C39'],
                ['name_ar' => 'بناء القدرات والتوعية والتدريب في مجال الأمن السيبراني.', 'source_ref' => 'Sheet1!C40'],
            ],
        ],
        [
            'code' => 'ai_data_analytics',
            'name_ar' => 'الذكاء الاصطناعي والبيانات والتحليلات المتقدمة',
            'name_en' => 'AI, Data & Analytics',
            'source_ref' => 'Sheet1!C41',
            'services' => [
                ['name_ar' => 'تطبيقات الذكاء الاصطناعي في العمليات الصناعية.', 'source_ref' => 'Sheet1!C42'],
                ['name_ar' => 'الصيانة التنبؤية باستخدام الذكاء الاصطناعي.', 'source_ref' => 'Sheet1!C43'],
                ['name_ar' => 'الفحص وضبط الجودة باستخدام الذكاء الاصطناعي والرؤية الحاسوبية. (  computer vision )', 'source_ref' => 'Sheet1!C44'],
                ['name_ar' => 'تحليل البيانات وذكاء الأعمال.', 'source_ref' => 'Sheet1!C45'],
                ['name_ar' => 'لوحات قياس الأداء الذكية ودعم اتخاذ القرار.', 'source_ref' => 'Sheet1!C46'],
                ['name_ar' => 'تحليل البيانات التشغيلية والاستراتيجية.', 'source_ref' => 'Sheet1!C47'],
                ['name_ar' => 'التنبؤ بالإنتاج والطلب والمخزون.', 'source_ref' => 'Sheet1!C48'],
            ],
        ],
        [
            'code' => 'digital_engineering_smart_manufacturing',
            'name_ar' => 'الهندسة الرقمية وتقنيات التصنيع الذكي',
            'name_en' => 'Digital Engineering & Smart Manufacturing',
            'source_ref' => 'Sheet1!C49',
            'services' => [
                ['name_ar' => 'التوأم الرقمي (Digital Twin).', 'source_ref' => 'Sheet1!C50'],
                ['name_ar' => 'المحاكاة الرقمية للعمليات وخطوط الإنتاج.', 'source_ref' => 'Sheet1!C51'],
                ['name_ar' => 'الواقع المعزز والافتراضي للصيانة والتدريب والتجميع.', 'source_ref' => 'Sheet1!C52'],
                ['name_ar' => 'حلول المصانع الذكية (Smart Factory).', 'source_ref' => 'Sheet1!C53'],
                ['name_ar' => 'الهندسة الرقمية للمنتجات والعمليات.', 'source_ref' => 'Sheet1!C54'],
            ],
        ],
        [
            'code' => 'dx_consulting_enablement',
            'name_ar' => 'الاستشارات ومُمكّنات التحول الرقمي',
            'name_en' => 'Digital Transformation Consulting & Enablement',
            'source_ref' => 'Sheet1!C55',
            'services' => [
                ['name_ar' => 'إعداد خارطة طريق للتحول الرقمي وتطبيقات الصناعة 4.0.', 'source_ref' => 'Sheet1!C56'],
                ['name_ar' => 'إعداد استراتيجيات التحول الرقمي.', 'source_ref' => 'Sheet1!C57'],
                ['name_ar' => 'إعادة هندسة ورقمنة العمليات.', 'source_ref' => 'Sheet1!C58'],
                ['name_ar' => 'إدارة التدريب والثقافة الرقمية.', 'source_ref' => 'Sheet1!C59:C60'],
                ['name_ar' => 'بناء القدرات والمهارات الرقمية.', 'source_ref' => 'Sheet1!C61'],
            ],
        ],
    ];

    /**
     * Run the database seeds. Safe to run repeatedly: rows are matched by code.
     */
    public function run(): void
    {
        foreach (self::CATEGORIES as $categoryPosition => $category) {
            $serviceCategory = ServiceCategory::query()->updateOrCreate(
                ['code' => $category['code']],
                [
                    'name_ar' => $category['name_ar'],
                    'name_en' => $category['name_en'],
                    'source_ref' => $category['source_ref'],
                    'sort_order' => $categoryPosition + 1,
                ],
            );

            foreach ($category['services'] as $servicePosition => $service) {
                CatalogService::query()->updateOrCreate(
                    ['code' => sprintf('%s.%02d', $category['code'], $servicePosition + 1)],
                    [
                        'service_category_id' => $serviceCategory->id,
                        'name_ar' => $service['name_ar'],
                        'source_ref' => $service['source_ref'],
                        'sort_order' => $servicePosition + 1,
                    ],
                );
            }
        }
    }
}
