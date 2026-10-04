<?php

namespace Database\Seeders;

use App\Models\EvaluationCriterion;
use Illuminate\Database\Seeder;

/**
 * Service Providers Evaluation Matrix, version 1 = the matrix as published in
 * التحول الصناعي الذكي, section 6, page 8. Weights total 100%.
 *
 * Seeding the criteria does not define a scoring scale or a pass mark; both are
 * still open (docs/open-questions.md OQ-13).
 */
class EvaluationCriterionSeeder extends Seeder
{
    public const SOURCE_VERSION = 1;

    /**
     * @var list<array{code: string, name_ar: string, sub_elements_ar: string, weight_percent: string, verification_ar: string}>
     */
    private const CRITERIA = [
        [
            'code' => 'technical_expertise',
            'name_ar' => 'الخبرة الفنية وسابقة الأعمال',
            'sub_elements_ar' => 'حجم المشروعات السابقة المماثلة في القطاع الصناعي، جودة الحلول البرمجية المقدمة، والالتزام بالمعايير الدولية (مثل ISO/IEC 62443 للأمن السيبراني).',
            'weight_percent' => '30.00',
            'verification_ar' => 'فحص عقود سابقة، معاينة منصات البرمجيات، وشهادات الاعتماد الدولية.',
        ],
        [
            'code' => 'technical_cloud_model',
            'name_ar' => 'النموذج التقني والسحابي (SaaS)',
            'sub_elements_ar' => 'جاهزية الحلول للعمل بنموذج الحوسبة السحابية (SaaS) وتحقيق مبدأ "الثلاثة أصفار" (صفر بنية تحتية، صفر استثمار رأسمالي، صفر تعطيل).',
            'weight_percent' => '25.00',
            'verification_ar' => 'استعراض البنية التقنية للمزود واختبار سرعة وثبات النشر السحابي.',
        ],
        [
            'code' => 'knowledge_transfer',
            'name_ar' => 'التزام "نقل المعرفة" (Knowledge Transfer)',
            'sub_elements_ar' => 'استعداد المزود الكامل لتدريب وتأهيل مهندسين اثنين (على الأقل) من كوادر المركز ميدانياً وعملياً طوال فترة التنفيذ.',
            'weight_percent' => '20.00',
            'verification_ar' => 'تقديم خطة تدريبية تفصيلية ومذكرة التزام قانونية مرفقة بالعقد.',
        ],
        [
            'code' => 'financial_flexibility',
            'name_ar' => 'المرونة المالية ونموذج المشاركة',
            'sub_elements_ar' => 'القبول بنموذج "المشاركة في العائد" (Revenue Sharing) بنسبة مرنة تتراوح بين (5% إلى 20%) بحسب دور المركز.',
            'weight_percent' => '15.00',
            'verification_ar' => 'مراجعة العرض المالي ونسبة العمولة المتفق عليها لصالح إيرادات المركز.',
        ],
        [
            'code' => 'technical_support_sla',
            'name_ar' => 'الدعم الفني ومستويات الخدمة (SLA)',
            'sub_elements_ar' => 'سرعة الاستجابة للأعطال، توافر فرق دعم فني محلية في مصر، وجودة اتفاقيات مستوى الخدمة بعد التسليم.',
            'weight_percent' => '10.00',
            'verification_ar' => 'تقييم اتفاقية مستوى الخدمة (SLA) وسجل استجابة الدعم الفني للمزود.',
        ],
    ];

    /**
     * Run the database seeds. Safe to run repeatedly: rows are matched by (version, code).
     */
    public function run(): void
    {
        foreach (self::CRITERIA as $position => $criterion) {
            EvaluationCriterion::query()->updateOrCreate(
                ['version' => self::SOURCE_VERSION, 'code' => $criterion['code']],
                [
                    'name_ar' => $criterion['name_ar'],
                    'name_en' => null,
                    'sub_elements_ar' => $criterion['sub_elements_ar'],
                    'weight_percent' => $criterion['weight_percent'],
                    'verification_ar' => $criterion['verification_ar'],
                    'sort_order' => $position + 1,
                ],
            );
        }
    }
}
