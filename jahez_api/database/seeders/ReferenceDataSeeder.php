<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Programme reference data taken from the source document (التحول الصناعي الذكي), the
 * services workbook (the service catalog) and the digital readiness framework (the
 * readiness questionnaire). Idempotent and safe in every environment; contains no
 * synthetic or personal data.
 */
class ReferenceDataSeeder extends Seeder
{
    /**
     * Run the database seeds in one transaction so a failure leaves no partial set.
     */
    public function run(): void
    {
        DB::transaction(fn () => $this->call([
            SectorSeeder::class,
            PathwaySeeder::class,
            MaturityTierSeeder::class,
            EvaluationCriterionSeeder::class,
            ServiceCatalogSeeder::class,
            ReadinessAssessmentSeeder::class,
        ]));
    }
}
