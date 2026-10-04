<?php

namespace Database\Seeders;

use App\Models\Sector;
use Illuminate\Database\Seeder;

/**
 * Strategic industrial sectors. Source: التحول الصناعي الذكي, section 1, page 2.
 * The source gives no English names (docs/open-questions.md OQ-23), so name_en stays null.
 */
class SectorSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name_ar: string}>
     */
    private const SECTORS = [
        ['code' => 'food', 'name_ar' => 'الصناعات الغذائية'],
        ['code' => 'chemical', 'name_ar' => 'الصناعات الكيماوية'],
        ['code' => 'engineering_metal', 'name_ar' => 'الصناعات الهندسية والمعدنية'],
        ['code' => 'medical_pharmaceutical', 'name_ar' => 'الصناعات الطبية والدوائية'],
    ];

    /**
     * Run the database seeds. Safe to run repeatedly: rows are matched by code.
     */
    public function run(): void
    {
        foreach (self::SECTORS as $position => $sector) {
            Sector::query()->updateOrCreate(
                ['code' => $sector['code']],
                ['name_ar' => $sector['name_ar'], 'name_en' => null, 'sort_order' => $position + 1],
            );
        }
    }
}
