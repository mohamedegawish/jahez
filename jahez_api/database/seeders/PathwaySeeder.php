<?php

namespace Database\Seeders;

use App\Models\LevelProviderRequirement;
use App\Models\Pathway;
use App\Models\PathwayLevel;
use App\Models\PathwayScopeItem;
use Illuminate\Database\Seeder;

/**
 * Transformation pathways, their levels, scope items and per-level provider requirements.
 * Source: التحول الصناعي الذكي, section 3 (The Transformation Pathways), pages 3–5.
 * English values are only those printed in the source itself (docs/open-questions.md OQ-23).
 */
class PathwaySeeder extends Seeder
{
    /**
     * Pathways: page 3 (path 1) and page 4 (path 2).
     *
     * @var list<array{code: string, name_ar: string, target_group_ar: string}>
     */
    private const PATHWAYS = [
        [
            'code' => 'foundational',
            'name_ar' => 'المسار الأول: الخدمات الاستشارية التأسيسية (ما قبل الرقمنة)',
            'target_group_ar' => 'المنشآت الصناعية ذات مستويات النضج التشغيلي، الإداري، أو التكنولوجي المنخفض الي المتوسط.',
        ],
        [
            'code' => 'digital_transformation',
            'name_ar' => 'المسار الثاني: خدمات التحول الرقمي والتكنولوجي',
            'target_group_ar' => 'المصانع المجتازة لمرحلة التأسيس، ويتم تقديم الخدمات عبر نموذج الحلول السحابية (SaaS) وفق مبدأ "الثلاثة أصفار" (صفر استثمار رأسمالي، صفر بنية تحتية، صفر تعطيل).',
        ],
    ];

    /**
     * Levels with their target groups (summary table, page 5), scope items
     * (نطاق العمل الفني والإداري) and provider requirements (الاشتراطات الفنية الخاصة بمقدم الخدمة).
     *
     * @var list<array{
     *     pathway: string,
     *     code: string,
     *     name_ar: string,
     *     subtitle_ar: string,
     *     name_en: string,
     *     target_group_ar: string,
     *     scope_items: list<array{group_ar: ?string, group_en: ?string, text_ar: string}>,
     *     provider_requirements: list<string>
     * }>
     */
    private const LEVELS = [
        [
            'pathway' => 'foundational',
            'code' => 'foundational',
            'name_ar' => 'التمكين التأسيسي وتأهيل البنية التحتية',
            'subtitle_ar' => 'الخدمات الاستشارية التأسيسية (ما قبل الرقمنة)',
            'name_en' => 'Foundational Pathway',
            'target_group_ar' => 'المنشآت ذات النضج التشغيلي/التكنولوجي المنخفض إلى المتوسط',
            'scope_items' => [
                ['group_ar' => 'التميز التشغيلي والتطوير المؤسسي', 'group_en' => 'Operational Excellence & BPR', 'text_ar' => 'التخطيط الاستراتيجي والحوكمة: صياغة الرؤية التشغيلية وإعادة الهيكلة التنظيمية بما يكفل كفاءة إدارة العمليات.'],
                ['group_ar' => 'التميز التشغيلي والتطوير المؤسسي', 'group_en' => 'Operational Excellence & BPR', 'text_ar' => 'إعادة هندسة العمليات (BPR): تبسيط وتحديث تدفقات العمليات الإدارية والإنتاجية لضمان مرونتها.'],
                ['group_ar' => 'التميز التشغيلي والتطوير المؤسسي', 'group_en' => 'Operational Excellence & BPR', 'text_ar' => 'منهجيات التصنيع الرشيق (Lean Manufacturing): تطبيق أدوات تنظيم بيئة العمل (5S)، ورسم خرائط تدفق القيمة (VSM)، وذلك للقضاء على أنشطة الفاقد غير المضيفة للقيمة وتحسين الأداء التشغيلي.'],
                ['group_ar' => 'تأهيل البنية التحتية الرقمية والتكنولوجية', 'group_en' => 'OT/IT Infrastructure Readiness', 'text_ar' => 'الشبكات الصناعية: تحديث وتأمين شبكات الاتصالات الداخلية بالمصنع لضمان موثوقية سرعة تدفق البيانات بين صالة الإنتاج والإدارة.'],
                ['group_ar' => 'تأهيل البنية التحتية الرقمية والتكنولوجية', 'group_en' => 'OT/IT Infrastructure Readiness', 'text_ar' => 'الخوادم والأنظمة: رفع كفاءة الخوادم وقواعد البيانات، وتأهيل أو رفع كفاءة الأنظمة القائمة (Legacy Systems).'],
                ['group_ar' => 'تأهيل البنية التحتية الرقمية والتكنولوجية', 'group_en' => 'OT/IT Infrastructure Readiness', 'text_ar' => 'التأهيل التكنولوجي: تأسيس بيئة متكاملة قادرة على استيعاب مشاريع الأتمتة الحديثة وشبكات إنترنت الأشياء الصناعي (IIoT) مستقبلاً.'],
            ],
            'provider_requirements' => [],
        ],
        [
            'pathway' => 'digital_transformation',
            'code' => 'basic_dx',
            'name_ar' => 'المستوى الأساسي',
            'subtitle_ar' => 'المؤسسة الرقمية',
            'name_en' => 'Basic DX',
            'target_group_ar' => 'المصانع المجتازة لمرحلة التأسيس بجاهزية إدارية',
            'scope_items' => [
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'رقمنة العمليات الوظيفية الأساسية وربط الإدارات الداخلية للمصنع ببعضها البعض.'],
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'تطبيق أنظمة تخطيط موارد المؤسسات (ERP) لتغطية الحسابات، المشتريات، المخازن، والمبيعات.'],
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'تطبيق أنظمة إدارة الموارد البشرية (HRMS) وشؤون العاملين والمرتبات.'],
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'تطبيق أنظمة إدارة علاقات العملاء (CRM) ومتابعة دورة حياة العملاء والطلبات.'],
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'إنشاء وتفعيل منظومة الأرشفة الإلكترونية المتكاملة لتنظيم وتأمين الوثائق الورقية والإلكترونية.'],
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'بناء لوحات المتابعة الإدارية (Executive Dashboards) لصناع القرار.'],
            ],
            'provider_requirements' => [
                'خبرة لا تقل عن 5 سنوات في تطبيق أنظمة (ERP) و(HRMS) للقطاع الصناعي.',
                'امتلاك رخصة برمجيات مرنة قابلة للتخصيص وقابلة للعمل بنموذج الحوسبة السحابية (SaaS) بالكامل.',
                'القدرة على تكامل النظم وتوفير واجهات برمجة التطبيقات (APIs) لربط الوحدات الإدارية المختلفة.',
            ],
        ],
        [
            'pathway' => 'digital_transformation',
            'code' => 'advanced_dx',
            'name_ar' => 'المستوى المتقدم',
            'subtitle_ar' => 'المصنع المتقدم',
            'name_en' => 'Advanced DX',
            'target_group_ar' => 'المصانع الراغبة في أتمته خطوط الإنتاج الفعلية',
            'scope_items' => [
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'تطوير شبكات الاتصالات الصناعية لضمان استقرار تدفق البيانات اللحظية (Real-time Data) بين الماكينات.'],
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'فصل شبكة الإدارة المكتبية (IT) عن شبكة خطوط الإنتاج والماكينات (OT) هندسياً لتأمين بيئة التشغيل.'],
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'رقمنه خطوط الإنتاج الفعلية وربط الماكينات والمعدات لجمع البيانات التشغيلية اللحظية (Real-time Data).'],
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'تطبيق نظم تنفيذ التصنيع (MES) للتحكم في أوامر التشغيل وتتبُّع المراحل الإنتاجية بدقة.'],
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'ربط الحساسات ووحدات إنترنت الأشياء الصناعية (IIoT) بالماكينات لمراقبة الأعطال وحالة التشغيل.'],
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'تطبيق أنظمة التتبع المتقدم (Med-Traceability / Advanced Traceability) لضمان تتبع خامات ومخرجات الإنتاج، خصوصاً في القطاعات الدوائية والغذائية والزراعية.'],
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'رقمنه سلاسل الإمداد الداخلية ضمن برنامج تطوير الموردين.'],
            ],
            'provider_requirements' => [
                'خبرة مثبتة في تكامل الأجهزة مع البرمجيات (OT/IT Integration) وتطبيق نظم (MES) و(IIoT).',
                'القدرة على التعامل مع بروتوكولات الاتصال الصناعية المختلفة (مثل OPC-UA, Modbus, MQTT).',
                'الالتزام بمعايير الجودة والتتبع المعتمدة محلياً ودولياً.',
            ],
        ],
        [
            'pathway' => 'digital_transformation',
            'code' => 'smart_dx',
            'name_ar' => 'المستوى الذكي',
            'subtitle_ar' => 'المصنع الذكي',
            'name_en' => 'Smart DX',
            'target_group_ar' => 'المصانع المتقدمة الباحثة عن التشغيل الذاتي والتنبؤي',
            'scope_items' => [
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'التحول الكامل للعمليات المعتمدة على تحليلات البيانات الضخمة (Big Data Analytics) والتنبؤ الذكي.'],
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'تطبيق الذكاء الاصطناعي في إدارة التشغيل والموارد البشرية (AI-Driven Operations & HR).'],
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'تطوير النماذج الافتراضية (Digital Twin) والتصميم الهندسي والمحاكاة لخطوط الإنتاج.'],
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'تطبيق أنظمة الصيانة التنبؤية (Predictive Maintenance) لتقليل الأعطال المفاجئة لأدنى حد.'],
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'تأمين شبكات التشغيل الصناعي (OT/ICS) وتطبيق معايير الأمن السيبراني الصناعي الصارمة.'],
                ['group_ar' => null, 'group_en' => null, 'text_ar' => 'توريد وتركيب أنظمة اكتشاف ومنع التسلل المخصصة للبيئات الصناعية (IDS/IPS).'],
            ],
            'provider_requirements' => [
                'سابق خبرة متقدمة في مشاريع الذكاء الاصطناعي والتوأم الرقمي والصيانة التنبؤية بالقطاع الصناعي.',
                'الالتزام التام بتطبيق معايير الأمن السيبراني الدولية للأتمتة وأنظمة التحكم الصناعي (ISO/IEC 62443).',
                'امتلاك كوادر استشارية وفنية متخصصة في تحليل البيانات الضخمة وأمن شبكات التشغيل.',
            ],
        ],
    ];

    /**
     * Run the database seeds. Safe to run repeatedly: pathways and levels are matched
     * by code, scope items and requirements by (level, position); surplus items are removed.
     */
    public function run(): void
    {
        $pathwayIds = [];

        foreach (self::PATHWAYS as $position => $pathway) {
            $pathwayIds[$pathway['code']] = Pathway::query()->updateOrCreate(
                ['code' => $pathway['code']],
                [
                    'name_ar' => $pathway['name_ar'],
                    'name_en' => null,
                    'target_group_ar' => $pathway['target_group_ar'],
                    'sort_order' => $position + 1,
                ],
            )->id;
        }

        foreach (self::LEVELS as $position => $level) {
            $pathwayLevel = PathwayLevel::query()->updateOrCreate(
                ['code' => $level['code']],
                [
                    'pathway_id' => $pathwayIds[$level['pathway']],
                    'name_ar' => $level['name_ar'],
                    'subtitle_ar' => $level['subtitle_ar'],
                    'name_en' => $level['name_en'],
                    'target_group_ar' => $level['target_group_ar'],
                    'sort_order' => $position + 1,
                ],
            );

            $this->syncScopeItems($pathwayLevel, $level['scope_items']);
            $this->syncProviderRequirements($pathwayLevel, $level['provider_requirements']);
        }
    }

    /**
     * @param  list<array{group_ar: ?string, group_en: ?string, text_ar: string}>  $scopeItems
     */
    private function syncScopeItems(PathwayLevel $pathwayLevel, array $scopeItems): void
    {
        foreach ($scopeItems as $position => $scopeItem) {
            PathwayScopeItem::query()->updateOrCreate(
                ['pathway_level_id' => $pathwayLevel->id, 'sort_order' => $position + 1],
                $scopeItem,
            );
        }

        PathwayScopeItem::query()
            ->whereBelongsTo($pathwayLevel)
            ->where('sort_order', '>', count($scopeItems))
            ->delete();
    }

    /**
     * @param  list<string>  $requirements
     */
    private function syncProviderRequirements(PathwayLevel $pathwayLevel, array $requirements): void
    {
        foreach ($requirements as $position => $requirement) {
            LevelProviderRequirement::query()->updateOrCreate(
                ['pathway_level_id' => $pathwayLevel->id, 'sort_order' => $position + 1],
                ['text_ar' => $requirement],
            );
        }

        LevelProviderRequirement::query()
            ->whereBelongsTo($pathwayLevel)
            ->where('sort_order', '>', count($requirements))
            ->delete();
    }
}
