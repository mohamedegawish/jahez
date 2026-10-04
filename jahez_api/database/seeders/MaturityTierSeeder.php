<?php

namespace Database\Seeders;

use App\Models\MaturityTier;
use App\Models\PathwayLevel;
use Illuminate\Database\Seeder;

/**
 * Client maturity tiers. Source: التحول الصناعي الذكي, section 4
 * (Client Assessment & Tiers Matrix), page 6.
 *
 * Numeric score thresholds are deliberately not seeded: the source gives qualitative
 * bands only (docs/open-questions.md OQ-07). Requires PathwaySeeder to have run.
 */
class MaturityTierSeeder extends Seeder
{
    /**
     * @var list<array{
     *     code: string,
     *     pathway_level: string,
     *     name_ar: string,
     *     name_en: string,
     *     readiness_band_ar: string,
     *     operational_state_ar: string,
     *     approved_path_ar: string,
     *     expected_impact_ar: string
     * }>
     */
    private const TIERS = [
        [
            'code' => 'foundation',
            'pathway_level' => 'foundational',
            'name_ar' => 'التأسيسي',
            'name_en' => 'Foundation Tier',
            'readiness_band_ar' => 'ضعيف / منخفض',
            'operational_state_ar' => 'فوضى تشغيلية، غياب الهياكل التنظيمي وغياب دليل العمليات التصنيعية، هدر عالٍ في الإنتاج اعتماد كامل على الورق.',
            'approved_path_ar' => 'المسار الأول: التمكين التأسيسي (Lean, 5S, BPR والهيكلة الإدارية) تحسين البنية التحتية.',
            'expected_impact_ar' => 'إعادة هندسة العمليات، خفض الهدر بنسبة تتراوح بين 20-30% خلال 3-6 أشهر.',
        ],
        [
            'code' => 'basic_dx',
            'pathway_level' => 'basic_dx',
            'name_ar' => 'الرقمي الأساسي',
            'name_en' => 'Basic DX Tier',
            'readiness_band_ar' => 'متوسط الأدنى',
            'operational_state_ar' => 'إدارة تقليدية مستقرة جزئياً، وجود بعض الأنظمة المنفصلة (سجلات منفردة)، رغبة في الربط المؤسسي.',
            'approved_path_ar' => 'المستوى الأساسي: تطبيق (ERP, HRMS, CRM) بنموذج SaaS.',
            'expected_impact_ar' => 'ربط الإدارات الداخلية، توحيد قواعد البيانات، وسرعة اتخاذ القرار الإداري (خلال 6 أشهر).',
        ],
        [
            'code' => 'advanced_dx',
            'pathway_level' => 'advanced_dx',
            'name_ar' => 'المصنع المتقدم',
            'name_en' => 'Advanced DX Tier',
            'readiness_band_ar' => 'متوسط الأعلى',
            'operational_state_ar' => 'خطوط إنتاج جيدة ولكنها تفتقر للرؤية اللحظية (Real-time data)، الحاجة لمتابعة كفاءة الماكينات (OEE).',
            'approved_path_ar' => 'المستوى المتقدم: نظم تنفيذ التصنيع (MES) وإنترنت الأشياء (IoT) وتتبع المنتجات.',
            'expected_impact_ar' => "رفع الكفاءة الإنتاجية الكلية (OEE) بنسب تصل إلى 50% وتتبع دقيق للمنتجات.\nدعم بروتوكولات (OPC-UA, Modbus, MQTT).",
        ],
        [
            'code' => 'smart_dx',
            'pathway_level' => 'smart_dx',
            'name_ar' => 'المصنع الذكي',
            'name_en' => 'Smart DX Tier',
            'readiness_band_ar' => 'متقدم / مرتفع',
            'operational_state_ar' => 'بنية تحتية تكنولوجية ناضجة، جاهزية للتعامل مع البيانات الضخمة والتشغيل المتقدم.',
            'approved_path_ar' => 'المستوى الذكي: تحليلات البيانات الضخمة والذكاء الاصطناعي (AI)، التوأم الرقمي، الصيانة التنبؤية، والأمن السيبراني.',
            'expected_impact_ar' => 'التحول الكامل لمنشأة ذكية تعتمد على التنبؤ الآلي، تقليل الأعطال المفاجئة لأدنى حد.',
        ],
    ];

    /**
     * Run the database seeds. Safe to run repeatedly: rows are matched by code.
     */
    public function run(): void
    {
        $pathwayLevelIds = PathwayLevel::query()->pluck('id', 'code');

        foreach (self::TIERS as $position => $tier) {
            MaturityTier::query()->updateOrCreate(
                ['code' => $tier['code']],
                [
                    'pathway_level_id' => $pathwayLevelIds[$tier['pathway_level']],
                    'name_ar' => $tier['name_ar'],
                    'name_en' => $tier['name_en'],
                    'readiness_band_ar' => $tier['readiness_band_ar'],
                    'operational_state_ar' => $tier['operational_state_ar'],
                    'approved_path_ar' => $tier['approved_path_ar'],
                    'expected_impact_ar' => $tier['expected_impact_ar'],
                    'sort_order' => $position + 1,
                ],
            );
        }
    }
}
