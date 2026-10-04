<?php

namespace Database\Seeders;

use App\Models\CatalogService;
use App\Models\ReadinessCategory;
use App\Models\ReadinessChoice;
use App\Models\ReadinessPillar;
use App\Models\ReadinessQuestion;
use App\Models\ReadinessQuestionnaire;
use App\Models\ReadinessRecommendation;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The digital readiness questionnaire, version 1 (ADR-018). Source:
 * docs/إطار تقييم مستوى الجاهزية الرقمية.docx — §1 the five pillars, §2 the points per
 * choice (أ 1, ب 2, ج 3, د 4), §3 the category table, §4 the questionnaire, §5 the
 * roadmap per category.
 *
 * - Text is the source text with surrounding whitespace trimmed, nothing else changed
 *   (the stray «)» after «(ERP).» and «(Workflow Management).» is kept: OQ-32).
 * - name_en / title_en hold only English the source prints (OQ-23 interim).
 * - Recommendation lines keep source order. The Basic list prints «بناء القدرات والتوعية
 *   والتدريب في مجال الأمن السيبراني.» twice (rows 7 and 9); it is seeded once.
 * - Each line maps to the existing catalog services whose name is the same text, and
 *   to no other: «إعادة هندسة ورقمنة العمليات.» names two catalog services and maps to
 *   both (owner decision 2026-10-03); «إدارة التدريب» is workbook row 59, the start of
 *   «إدارة التدريب والثقافة الرقمية.» (owner decision on rows 59–60). Lines the catalog
 *   does not offer map to nothing (OQ-42). No catalog service is created here.
 *
 * Requires ServiceCatalogSeeder. tests/Feature/Database/ReadinessAssessmentSourceTest.php
 * checks this data against the .docx itself.
 *
 * Safe to run repeatedly: rows are matched by stable codes. Text corrections are applied
 * in place, but once factories have answered a version its questions, choices, points,
 * pillars and category ranges cannot change: that needs a new version, so recorded
 * results stay reproducible.
 */
class ReadinessAssessmentSeeder extends Seeder
{
    public const SOURCE = 'إطار تقييم مستوى الجاهزية الرقمية.docx';

    /**
     * @var array{version: int, title_ar: string, title_en: string, source_ref: string}
     */
    public const QUESTIONNAIRE = [
        'version' => 1,
        'title_ar' => 'إطار تقييم مستوى الجاهزية الرقمية',
        'title_en' => 'Digital Readiness Level Assessment',
        'source_ref' => self::SOURCE,
    ];

    /**
     * §2: the points each choice is worth, in choice order.
     *
     * @var array<string, array{label_ar: string, points: int}>
     */
    public const CHOICES = [
        'a' => ['label_ar' => 'أ', 'points' => 1],
        'b' => ['label_ar' => 'ب', 'points' => 2],
        'c' => ['label_ar' => 'ج', 'points' => 3],
        'd' => ['label_ar' => 'د', 'points' => 4],
    ];

    /**
     * §1 and §4: the pillars in source order, each with its two questions and the four
     * choices of each question in the order أ ب ج د.
     *
     * @var list<array{code: string, name_ar: string, name_en: string, source_ref: string, questions: list<array{number: int, text_ar: string, choices: list<string>}>}>
     */
    public const PILLARS = [
        [
            'code' => 'strategy_leadership',
            'name_ar' => 'القيادة والحوكمة',
            'name_en' => 'Strategy & Leadership',
            'source_ref' => '§4 المحور الأول',
            'questions' => [
                [
                    'number' => 1,
                    'text_ar' => 'كيف تصف استراتيجية التحول الرقمي في مؤسستك حالياً؟',
                    'choices' => [
                        'لا توجد استراتيجية رقمية محددة، والتركيز على الحلول اليومية اليدوية.',
                        'توجد أفكار أو مبادرات رقمية غير مترابطة وبدون ميزانية محددة.',
                        'توجد استراتيجية رقمية واضحة ومربوطة بأهداف الشركة ولها ميزانية مخصصة.',
                        'التحول الرقمي محرك أساسي لنموذج العمل، مع مراجعة وتحديث مستمر للاستراتيجية.',
                    ],
                ],
                [
                    'number' => 2,
                    'text_ar' => 'كيف يتم قياس ومتابعة أداء المبادرات الرقمية؟',
                    'choices' => [
                        'لا يتم قياسها مطلقاً.',
                        'نعتمد على الانطباعات العامة أو متابعة غير منتظمة.',
                        'نستخدم مؤشرات أداء رئيسية (KPIs) محددة لكل مشروع رقمي.',
                        'نستخدم لوحات قياس لحظية (Real-time Dashboards) لربط الاستثمار الرقمي بالعائد المالي مباشرة.',
                    ],
                ],
            ],
        ],
        [
            'code' => 'processes_operations',
            'name_ar' => 'العمليات التشغيلية',
            'name_en' => 'Processes & Operations',
            'source_ref' => '§4 المحور الثاني',
            'questions' => [
                [
                    'number' => 3,
                    'text_ar' => 'ما مدى اعتماد دورات العمل (Workflows) داخل الشركة على المعاملات الورقية أو اليدوية؟',
                    'choices' => [
                        'معظم المعاملات والاعتمادات تُدار ورقياً أو عبر الإيميل التقليدي.',
                        'تم تحويل بعض المستندات لملفات رقمية (PDF/Excel) لكن الاعتمادات ما زالت يدوية.',
                        'معظم دورات العمل ومسارات الاعتماد مؤتمتة تماماً عبر أنظمة إلكترونية.',
                        'العمليات تُدار ذاتياً بأتمتة ذكية (RPA) ولا تتطلب تدخلاً بشرياً إلا في الحالات الاستثنائية.',
                    ],
                ],
                [
                    'number' => 4,
                    'text_ar' => 'كيف يتم التواصل والربط بين الإدارات المختلفة لتنفيذ العمليات؟',
                    'choices' => [
                        'كل إدارة تعمل بشكل منفصل تماماً (Silos) والتواصل عبر المكالمات والورق.',
                        'يوجد تواصل عبر برامج المراسلة والبريد الإلكتروني فقط.',
                        'الأنظمة الأساسية للإدارات مرتبطة ببعضها من خلال نظام إداري موحد (مثل ERP / CRM).',
                        'جميع الأنظمة والعمليات متصلة بشكل متكامل وآلي عبر منصات منظمة ومنظومة APIs.',
                    ],
                ],
            ],
        ],
        [
            'code' => 'technology_data',
            'name_ar' => 'التكنولوجيا والبيانات',
            'name_en' => 'Technology & Data',
            'source_ref' => '§4 المحور الثالث',
            'questions' => [
                [
                    'number' => 5,
                    'text_ar' => 'ما هو وضع البنية التحتية والأنظمة الرقمية المستخدمة لديكم؟',
                    'choices' => [
                        'نعتمد على برامج قديمة جداً أو ملفات Excel بسيطة وغير محدثة.',
                        'نستخدم برامج جاهزة منفصلة محلياً (On-Premise) بدون ربط بينها.',
                        'نعتمد بشكل أساسي على الحلول السحابية (Cloud Infrastructure / SaaS).',
                        'نعتمد على بيئة سحابية متطورة مع مرونة عالية لاستيعاب أي تقنيات حديثة بسرعة.',
                    ],
                ],
                [
                    'number' => 6,
                    'text_ar' => 'كيف تتعامل المؤسسة مع البيانات لاتخاذ القرارات؟',
                    'choices' => [
                        'البيانات مبعثرة وصعبة التجميع، والقرارات تعتمد على الخبرة الشخصية فقط.',
                        'يتم تجميع البيانات يدوياً في تقارير دورية يستغرق إعدادها وقتاً طويلاً.',
                        'نملك قاعدة بيانات موحدة ولوحات قياس (Business Intelligence) تعكس الوضع الحالي.',
                        'نستخدم نماذج تحليل تنبؤية (Predictive Analytics) وذكاء اصطناعي لدعم واتخاذ القرارات تلقائياً.',
                    ],
                ],
            ],
        ],
        [
            'code' => 'culture_people',
            'name_ar' => 'الثقافة والكوادر البشرية',
            'name_en' => 'Culture & People',
            'source_ref' => '§4 المحور الرابع',
            'questions' => [
                [
                    'number' => 7,
                    'text_ar' => 'ما مدى جاهزية ومهارة الموظفين لاستخدام وتطبيق التقنيات الحديثة؟',
                    'choices' => [
                        'ضعف في المهارات الرقمية ومقاومة عالية لأي تغيير تقني.',
                        'معرفة أساسية بالتقنيات مع الحاجة لتغلب الموظفين على صعوبات الاستخدام.',
                        'الموظفون يمتلكون مهارات رقمية جيدة ويشاركون في دورات تدريبية منتظمة.',
                        'ثقافة ابتكار رقمي واسعة، والموظفون يقترحون أدوات وتقنيات حديثة لتطوير أعمالهم.',
                    ],
                ],
                [
                    'number' => 8,
                    'text_ar' => 'كيف يتعامل الهيكل التنظيمي مع تغييرات وإدارة مشاريع التحول الرقمي؟',
                    'choices' => [
                        'لا يوجد أي شخص أو فريق مسؤول عن التغيير أو التقنية.',
                        'المهام ملقاة بالكامل على قسم IT فقط دون مشاركة بقية الأقسام.',
                        'يوجد فريق مخصص لإدارة التحول الرقمي والتغيير التنظيمي بالتنسيق مع الإدارات.',
                        'هيكل مرن (Agile) يضم فرق عمل متكاملة من مختلف التخصصات لتطوير الخدمات باستمرار.',
                    ],
                ],
            ],
        ],
        [
            'code' => 'customer_experience',
            'name_ar' => 'تجربة العملاء',
            'name_en' => 'Customer Experience',
            'source_ref' => '§4 المحور الخامس',
            'questions' => [
                [
                    'number' => 9,
                    'text_ar' => 'ما هي القنوات التي يستخدمها عملاؤك للتفاعل والحصول على خدماتك؟',
                    'choices' => [
                        'الحضور الشخصي، أو الاتصال التليفوني التقليدي فقط.',
                        'توجد قنوات رقمية بسيطة (مثل صفحة فيسبوك أو نموذج اتصال) غير مربوطة بالأنظمة.',
                        'موقع إلكتروني وتطبيق هاتف يتيح للعميل تنفيذ وتتبع معظم الخدمات بنفسه.',
                        'تجربة عميل متعددة القنوات ومترابطة (Omnichannel) توفر استجابة فورية وتكيفية.',
                    ],
                ],
                [
                    'number' => 10,
                    'text_ar' => 'كيف يتم جمع وتحليل آراء العملاء (Customer Feedback) لتطوير الخدمات؟',
                    'choices' => [
                        'لا يتم جمع آراء العملاء بطريقة منظمة.',
                        'يتم جمع الآراء عبر استبيانات يدوية أو اتصالات عشوائية من وقت لآخر.',
                        'يتم جمع التقييمات آلياً بعد الخدمة وتحليلها دورياً لتحسين الأداء.',
                        'تحليل فوري وسلوكي لآراء وانطباعات العملاء عبر أدوات ذكاء اصطناعي لتعديل الخدمة لحظياً.',
                    ],
                ],
            ],
        ],
    ];

    /**
     * §3 (name, description, score range) and §5 (focus, steps, recommended services):
     * the categories in source order. `services` are catalog codes (ServiceCatalogSeeder).
     *
     * @var list<array{code: string, name_en: string, name_ar: string, description_ar: string, min_score: int, max_score: int, focus_ar: string, steps_ar: string, recommendations: list<array{text_ar: string, source_ref: string, services: list<string>}>}>
     */
    public const CATEGORIES = [
        [
            'code' => 'b4_automation',
            'name_en' => 'B4 Automation',
            'name_ar' => 'ما قبل الأتمتة',
            'description_ar' => 'الاعتماد الكلي على العمليات الورقية واليدوية، مع غياب الرؤية والاستراتيجية الرقمية.',
            'min_score' => 10,
            'max_score' => 17,
            'focus_ar' => 'البداية من الأساسيات.',
            'steps_ar' => 'حصر جميع العمليات الورقية، البدء برقمنة المستندات، واختيار نظام إدارة موارد (ERP) أو إدارة علاقات عملاء (CRM) أساسي.',
            'recommendations' => [
                ['text_ar' => 'نظم تخطيط وإدارة موارد المؤسسات (ERP).)', 'source_ref' => '§5 B4 Automation, row 1', 'services' => ['erp_business_applications.01']],
                ['text_ar' => 'رقمنة وأتمتة العمليات والإجراءات الإدارية والتشغيلية.', 'source_ref' => '§5 B4 Automation, row 2', 'services' => ['erp_business_applications.02']],
                ['text_ar' => 'إدارة سير العمل والإجراءات (Workflow Management).)', 'source_ref' => '§5 B4 Automation, row 3', 'services' => ['erp_business_applications.03']],
                ['text_ar' => 'إعادة هندسة ورقمنة العمليات.', 'source_ref' => '§5 B4 Automation, row 4', 'services' => ['erp_business_applications.04', 'dx_consulting_enablement.03']],
                ['text_ar' => 'الموارد البشرية', 'source_ref' => '§5 B4 Automation, row 5', 'services' => []],
                ['text_ar' => 'خدمات الإنتاج ( 5s- lean – الجودة )', 'source_ref' => '§5 B4 Automation, row 6', 'services' => []],
                ['text_ar' => 'تقييم البنية التحتية', 'source_ref' => '§5 B4 Automation, row 7', 'services' => []],
                ['text_ar' => 'إعداد خارطة طريق للتحول الرقمي وتطبيقات الصناعة 4.0.', 'source_ref' => '§5 B4 Automation, row 8', 'services' => ['dx_consulting_enablement.01']],
                ['text_ar' => 'إعداد استراتيجيات التحول الرقمي.', 'source_ref' => '§5 B4 Automation, row 9', 'services' => ['dx_consulting_enablement.02']],
                ['text_ar' => 'إدارة التدريب', 'source_ref' => '§5 B4 Automation, row 10', 'services' => ['dx_consulting_enablement.04']],
                ['text_ar' => 'بناء القدرات والمهارات الرقمية.', 'source_ref' => '§5 B4 Automation, row 11', 'services' => ['dx_consulting_enablement.05']],
            ],
        ],
        [
            'code' => 'basic',
            'name_en' => 'Basic',
            'name_ar' => 'مبتدئ / رقمنة أساسية',
            'description_ar' => 'استخدام أدوات وأنظمة معزولة (Silos)، أتمتة بسيطة جداً، وبداية صياغة استراتيجية.',
            'min_score' => 18,
            'max_score' => 25,
            'focus_ar' => 'الربط والأتمتة البسيطة.',
            'steps_ar' => 'التركيز على الربط والتكامل (Integration) بين الأنظمة المعزولة، وأتمتة مسارات العمل المكررة (Workflows).',
            'recommendations' => [
                ['text_ar' => 'نظم تنفيذ التصنيع (MES).', 'source_ref' => '§5 Basic, row 1', 'services' => ['automation_ot.01']],
                ['text_ar' => 'نظم المراقبة والتحكم الصناعي (SCADA).', 'source_ref' => '§5 Basic, row 2', 'services' => ['automation_ot.02']],
                ['text_ar' => 'إنترنت الأشياء الصناعي (IIoT).', 'source_ref' => '§5 Basic, row 3', 'services' => ['automation_ot.03']],
                ['text_ar' => 'ربط الآلات وخطوط الإنتاج وجمع بيانات التشغيل.', 'source_ref' => '§5 Basic, row 4', 'services' => ['automation_ot.04']],
                ['text_ar' => 'تقييم الجاهزية والفجوات في الأمن السيبراني.', 'source_ref' => '§5 Basic, row 5', 'services' => ['ot_ics_cybersecurity.02']],
                ['text_ar' => 'تقييم البنية التحتية', 'source_ref' => '§5 Basic, row 6', 'services' => []],
                ['text_ar' => 'بناء القدرات والتوعية والتدريب في مجال الأمن السيبراني.', 'source_ref' => '§5 Basic, rows 7 and 9', 'services' => ['ot_ics_cybersecurity.07']],
                ['text_ar' => 'تطبيقات الذكاء الاصطناعي في العمليات الصناعية.', 'source_ref' => '§5 Basic, row 8', 'services' => ['ai_data_analytics.01']],
            ],
        ],
        [
            'code' => 'advanced',
            'name_en' => 'Advanced',
            'name_ar' => 'متقدم',
            'description_ar' => 'ربط وتكامل بين الأنظمة (Integration)، اتخاذ قرارات بناءً على البيانات، وأتمتة واسعة.',
            'min_score' => 26,
            'max_score' => 33,
            'focus_ar' => 'التحليل والربط السحابي.',
            'steps_ar' => 'الاعتماد الكامل على البيانات اللحظية (BI)، تحسين البنية السحابية، والبدء في إدخال الذكاء الاصطناعي الأولي لتجربة العملاء.',
            'recommendations' => [
                ['text_ar' => 'دمج الروبوتات والروبوتات التعاونية (Cobots) لرفع كفاءة خطوط الإنتاج.', 'source_ref' => '§5 Advanced, row 1', 'services' => ['automation_ot.05']],
                ['text_ar' => 'نظم إدارة الطاقة والمرافق (EMS).', 'source_ref' => '§5 Advanced, row 2', 'services' => ['automation_ot.06']],
                ['text_ar' => 'نظم إدارة المخازن والمستودعات (WMS).', 'source_ref' => '§5 Advanced, row 3', 'services' => ['automation_ot.07']],
                ['text_ar' => 'التكامل بين أنظمة التشغيل والمعلومات (OT/IT) وربطها بأنظمة ERP وMES.', 'source_ref' => '§5 Advanced, row 4', 'services' => ['automation_ot.08']],
                ['text_ar' => 'الحلول السحابية والبنية السحابية', 'source_ref' => '§5 Advanced, row 5', 'services' => ['cloud_infrastructure.01']],
                ['text_ar' => 'البنية التحتية الرقمية ومراكز البيانات.', 'source_ref' => '§5 Advanced, row 6', 'services' => ['cloud_infrastructure.02']],
                ['text_ar' => 'تخزين وإدارة البيانات.', 'source_ref' => '§5 Advanced, row 7', 'services' => ['cloud_infrastructure.03']],
                ['text_ar' => 'تقييم الفجوات واختبارات الاختراق.', 'source_ref' => '§5 Advanced, row 8', 'services' => ['ot_ics_cybersecurity.03']],
                ['text_ar' => 'الحماية من هجمات والتهديدات السيبرانية.', 'source_ref' => '§5 Advanced, row 9', 'services' => ['ot_ics_cybersecurity.04']],
                ['text_ar' => 'حماية استمرارية العمليات التشغيلية.', 'source_ref' => '§5 Advanced, row 10', 'services' => ['ot_ics_cybersecurity.05']],
                ['text_ar' => 'حوكمة وإدارة الأمن السيبراني.', 'source_ref' => '§5 Advanced, row 11', 'services' => ['ot_ics_cybersecurity.06']],
                ['text_ar' => 'الصيانة التنبؤية باستخدام الذكاء الاصطناعي.', 'source_ref' => '§5 Advanced, row 12', 'services' => ['ai_data_analytics.02']],
                ['text_ar' => 'الفحص وضبط الجودة باستخدام الذكاء الاصطناعي والرؤية الحاسوبية. (  computer vision )', 'source_ref' => '§5 Advanced, row 13', 'services' => ['ai_data_analytics.03']],
                ['text_ar' => 'تحليل البيانات وذكاء الأعمال.', 'source_ref' => '§5 Advanced, row 14', 'services' => ['ai_data_analytics.04']],
                ['text_ar' => 'لوحات قياس الأداء الذكية ودعم اتخاذ القرار.', 'source_ref' => '§5 Advanced, row 15', 'services' => ['ai_data_analytics.05']],
            ],
        ],
        [
            'code' => 'smart',
            'name_en' => 'Smart',
            'name_ar' => 'ذكي ومبتكر',
            'description_ar' => 'بيئة تعتمد على الذكاء الاصطناعي (AI)، الأتمتة الذكية، والتحليل التنبؤي مع ثقافة ابتكار مستمرة.',
            'min_score' => 34,
            'max_score' => 40,
            'focus_ar' => 'الابتكار والذكاء الاصطناعي.',
            'steps_ar' => 'التوسع في الذكاء الاصطناعي التوليدي والتنبؤي، بناء منظومة ابتكار رقمي مستمرة، والقيادة الابتكارية في القطاع.',
            'recommendations' => [
                ['text_ar' => 'تخزين وإدارة البيانات.', 'source_ref' => '§5 Smart, row 1', 'services' => ['cloud_infrastructure.03']],
                ['text_ar' => 'حلول النسخ الاحتياطي واستعادة البيانات.', 'source_ref' => '§5 Smart, row 2', 'services' => ['cloud_infrastructure.04']],
                ['text_ar' => 'تكامل الأنظمة والمنصات الرقمية.', 'source_ref' => '§5 Smart, row 3', 'services' => ['cloud_infrastructure.05']],
                ['text_ar' => 'دمج الروبوتات والروبوتات التعاونية (Cobots) لرفع كفاءة خطوط الإنتاج.', 'source_ref' => '§5 Smart, row 4', 'services' => ['automation_ot.05']],
                ['text_ar' => 'نظم إدارة الطاقة والمرافق (EMS).', 'source_ref' => '§5 Smart, row 5', 'services' => ['automation_ot.06']],
                ['text_ar' => 'نظم إدارة المخازن والمستودعات (WMS).', 'source_ref' => '§5 Smart, row 6', 'services' => ['automation_ot.07']],
                ['text_ar' => 'التكامل بين أنظمة التشغيل والمعلومات (OT/IT) وربطها بأنظمة ERP وMES.', 'source_ref' => '§5 Smart, row 7', 'services' => ['automation_ot.08']],
                ['text_ar' => 'حماية استمرارية العمليات التشغيلية.', 'source_ref' => '§5 Smart, row 8', 'services' => ['ot_ics_cybersecurity.05']],
                ['text_ar' => 'حوكمة وإدارة الأمن السيبراني.', 'source_ref' => '§5 Smart, row 9', 'services' => ['ot_ics_cybersecurity.06']],
                ['text_ar' => 'بناء القدرات والتوعية والتدريب في مجال الأمن السيبراني.', 'source_ref' => '§5 Smart, row 10', 'services' => ['ot_ics_cybersecurity.07']],
                ['text_ar' => 'التوأم الرقمي (Digital Twin).', 'source_ref' => '§5 Smart, row 11', 'services' => ['digital_engineering_smart_manufacturing.01']],
                ['text_ar' => 'المحاكاة الرقمية للعمليات وخطوط الإنتاج.', 'source_ref' => '§5 Smart, row 12', 'services' => ['digital_engineering_smart_manufacturing.02']],
                ['text_ar' => 'الواقع المعزز والافتراضي للصيانة والتدريب والتجميع.', 'source_ref' => '§5 Smart, row 13', 'services' => ['digital_engineering_smart_manufacturing.03']],
                ['text_ar' => 'حلول المصانع الذكية (Smart Factory).', 'source_ref' => '§5 Smart, row 14', 'services' => ['digital_engineering_smart_manufacturing.04']],
            ],
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $serviceIds = $this->catalogServiceIds();

        $questionnaire = ReadinessQuestionnaire::query()->firstOrNew(['version' => self::QUESTIONNAIRE['version']]);
        $isAnswered = $questionnaire->exists && $questionnaire->assessments()->exists();

        if ($isAnswered && $this->scoringOf($questionnaire) !== $this->scoringFromSource()) {
            throw new RuntimeException('Readiness questionnaire version '.self::QUESTIONNAIRE['version'].' has assessments; its questions, choices, points, pillars and category ranges cannot change. Publish a new version instead.');
        }

        $questionnaire->fill(self::QUESTIONNAIRE)->save();

        foreach (self::PILLARS as $pillarPosition => $pillarData) {
            $pillar = ReadinessPillar::query()->updateOrCreate(
                ['readiness_questionnaire_id' => $questionnaire->id, 'code' => $pillarData['code']],
                ['name_ar' => $pillarData['name_ar'], 'name_en' => $pillarData['name_en'], 'sort_order' => $pillarPosition + 1],
            );

            foreach ($pillarData['questions'] as $questionData) {
                $question = ReadinessQuestion::query()->updateOrCreate(
                    ['readiness_questionnaire_id' => $questionnaire->id, 'code' => 'q'.$questionData['number']],
                    [
                        'readiness_pillar_id' => $pillar->id,
                        'number' => $questionData['number'],
                        'text_ar' => $questionData['text_ar'],
                        'source_ref' => "{$pillarData['source_ref']}, س{$questionData['number']}",
                    ],
                );

                foreach (array_keys(self::CHOICES) as $choicePosition => $choiceCode) {
                    ReadinessChoice::query()->updateOrCreate(
                        ['readiness_question_id' => $question->id, 'code' => $choiceCode],
                        [
                            'label_ar' => self::CHOICES[$choiceCode]['label_ar'],
                            'text_ar' => $questionData['choices'][$choicePosition],
                            'points' => self::CHOICES[$choiceCode]['points'],
                            'sort_order' => $choicePosition + 1,
                        ],
                    );
                }
            }
        }

        foreach (self::CATEGORIES as $categoryPosition => $categoryData) {
            $category = ReadinessCategory::query()->updateOrCreate(
                ['readiness_questionnaire_id' => $questionnaire->id, 'code' => $categoryData['code']],
                [
                    'name_en' => $categoryData['name_en'],
                    'name_ar' => $categoryData['name_ar'],
                    'description_ar' => $categoryData['description_ar'],
                    'min_score' => $categoryData['min_score'],
                    'max_score' => $categoryData['max_score'],
                    'focus_ar' => $categoryData['focus_ar'],
                    'steps_ar' => $categoryData['steps_ar'],
                    'sort_order' => $categoryPosition + 1,
                ],
            );

            foreach ($categoryData['recommendations'] as $recommendationPosition => $recommendationData) {
                $recommendation = ReadinessRecommendation::query()->updateOrCreate(
                    ['readiness_category_id' => $category->id, 'sort_order' => $recommendationPosition + 1],
                    ['text_ar' => $recommendationData['text_ar'], 'source_ref' => $recommendationData['source_ref']],
                );
                $recommendation->services()->sync(array_map(fn (string $code): int => $serviceIds[$code], $recommendationData['services']));
            }

            ReadinessRecommendation::query()
                ->whereBelongsTo($category, 'category')
                ->where('sort_order', '>', count($categoryData['recommendations']))
                ->delete();
        }

        if (! $isAnswered) {
            $this->removeSurplusItems($questionnaire);
        }

        // Version 1 becomes current only when no version is: once IMC administrators
        // publish a later version, re-seeding must not switch factories back to this one.
        if (! ReadinessQuestionnaire::query()->where('is_current', true)->exists()) {
            ReadinessQuestionnaire::query()->whereKey($questionnaire->id)->update(['is_current' => true]);
        }

        if (ReadinessQuestionnaire::query()->whereKey($questionnaire->id)->where('is_current', true)->whereNull('published_at')->exists()) {
            ReadinessQuestionnaire::query()->whereKey($questionnaire->id)->update(['published_at' => now()]);
        }
    }

    /**
     * Catalog service ids by code, for every code a recommendation names.
     *
     * @return array<string, int>
     */
    private function catalogServiceIds(): array
    {
        $codes = array_values(array_unique(array_merge(...array_map(
            fn (array $category): array => array_merge(...array_column($category['recommendations'], 'services')),
            self::CATEGORIES,
        ))));
        $serviceIds = CatalogService::query()->whereIn('code', $codes)->pluck('id', 'code')->all();
        $missing = array_diff($codes, array_keys($serviceIds));

        if ($missing !== []) {
            throw new RuntimeException('Unknown catalog services in the readiness recommendations: '.implode(', ', $missing).'. Run ServiceCatalogSeeder first.');
        }

        return $serviceIds;
    }

    /**
     * What decides a recorded result, as stored: pillar of each question, points of each
     * choice, and the range of each category.
     *
     * @return array{questions: array<string, array{pillar: string|null, choices: array<string, int>}>, categories: array<string, array{int, int}>}
     */
    private function scoringOf(ReadinessQuestionnaire $questionnaire): array
    {
        $questions = [];
        foreach ($questionnaire->questions()->with(['pillar', 'choices'])->get() as $question) {
            $questions[$question->code] = [
                'pillar' => $question->pillar?->code,
                'choices' => $question->choices->mapWithKeys(fn (ReadinessChoice $choice): array => [$choice->code => $choice->points])->all(),
            ];
        }

        $categories = [];
        foreach ($questionnaire->categories()->get() as $category) {
            $categories[$category->code->value] = [$category->min_score, $category->max_score];
        }
        ksort($questions);
        ksort($categories);

        return ['questions' => $questions, 'categories' => $categories];
    }

    /**
     * The same as scoringOf(), from the source data in this class.
     *
     * @return array{questions: array<string, array{pillar: string|null, choices: array<string, int>}>, categories: array<string, array{int, int}>}
     */
    private function scoringFromSource(): array
    {
        $questions = [];
        foreach (self::PILLARS as $pillar) {
            foreach ($pillar['questions'] as $question) {
                $questions['q'.$question['number']] = [
                    'pillar' => $pillar['code'],
                    'choices' => array_map(fn (array $choice): int => $choice['points'], self::CHOICES),
                ];
            }
        }

        $categories = [];
        foreach (self::CATEGORIES as $category) {
            $categories[$category['code']] = [$category['min_score'], $category['max_score']];
        }
        ksort($questions);
        ksort($categories);

        return ['questions' => $questions, 'categories' => $categories];
    }

    /**
     * Removes questions, choices, pillars and categories the source no longer lists.
     * Only for a version nobody has answered yet.
     */
    private function removeSurplusItems(ReadinessQuestionnaire $questionnaire): void
    {
        $questionCodes = [];
        foreach (self::PILLARS as $pillar) {
            foreach ($pillar['questions'] as $question) {
                $questionCodes[] = 'q'.$question['number'];
            }
        }

        $questionIds = $questionnaire->questions()->pluck('id');
        ReadinessChoice::query()->whereIn('readiness_question_id', $questionIds)->whereNotIn('code', array_keys(self::CHOICES))->delete();

        $surplusQuestionIds = $questionnaire->questions()->whereNotIn('code', $questionCodes)->pluck('id');
        ReadinessChoice::query()->whereIn('readiness_question_id', $surplusQuestionIds)->delete();
        ReadinessQuestion::query()->whereKey($surplusQuestionIds)->delete();

        ReadinessPillar::query()->whereBelongsTo($questionnaire, 'questionnaire')->whereNotIn('code', array_column(self::PILLARS, 'code'))->delete();

        $surplusCategoryIds = $questionnaire->categories()->whereNotIn('code', array_column(self::CATEGORIES, 'code'))->pluck('id');
        ReadinessRecommendation::query()->whereIn('readiness_category_id', $surplusCategoryIds)->delete();
        ReadinessCategory::query()->whereKey($surplusCategoryIds)->delete();
    }
}
