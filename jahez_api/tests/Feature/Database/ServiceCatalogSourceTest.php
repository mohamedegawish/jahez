<?php

use App\Models\CatalogService;
use App\Models\ServiceCategory;
use Database\Seeders\ReferenceDataSeeder;

/**
 * Columns A–C of Sheet1 of the services workbook, keyed by row number, read from the
 * .xlsx file itself so the catalog is checked against the source and not against a copy.
 *
 * @return array<int, array<string, string>>
 */
function serviceWorkbookRows(): array
{
    $archive = new ZipArchive;
    $archive->open(base_path('docs/Copy of الخدمات التحول الرقمي.xlsx'));

    $sharedStrings = [];
    foreach (simplexml_load_string((string) $archive->getFromName('xl/sharedStrings.xml'))->si as $item) {
        $text = (string) $item->t;
        foreach ($item->r as $run) {
            $text .= (string) $run->t;
        }
        $sharedStrings[] = $text;
    }

    $rows = [];
    foreach (simplexml_load_string((string) $archive->getFromName('xl/worksheets/sheet1.xml'))->sheetData->row as $row) {
        foreach ($row->c as $cell) {
            preg_match('/^([A-Z]+)(\d+)$/', (string) $cell['r'], $reference);
            $value = (string) $cell['t'] === 's' ? $sharedStrings[(int) $cell->v] : (string) $cell->v;
            $rows[(int) $reference[2]][$reference[1]] = $value;
        }
    }
    $archive->close();

    return $rows;
}

/**
 * The catalog as the workbook states it, by the rules documented in ServiceCatalogSeeder:
 * category rows are those labelled «الفئة…» in column B; the category name drops its list
 * number and splits off the English in parentheses; services are the column-C texts below
 * it, trimmed. Rows 59–60 are joined (owner decision 2026-10-03).
 *
 * @return list<array{name_ar: string, name_en: string, source_ref: string, services: list<array{name_ar: string, source_ref: string}>}>
 */
function catalogStatedByWorkbook(): array
{
    $rows = serviceWorkbookRows();
    $categories = [];

    for ($row = 12; $row <= 61; $row++) {
        $label = trim($rows[$row]['B'] ?? '');
        $text = trim($rows[$row]['C'] ?? '');

        if (str_starts_with($label, 'الفئة')) {
            preg_match('/^(?:\d+\.\s*)?(.*?)\s*\(([^()]+)\)$/u', $text, $name);
            $categories[] = ['name_ar' => $name[1], 'name_en' => $name[2], 'source_ref' => "Sheet1!C{$row}", 'services' => []];
        } elseif ($text !== '') {
            $categories[array_key_last($categories)]['services'][] = ['name_ar' => $text, 'source_ref' => "Sheet1!C{$row}"];
        }
    }

    $consulting = &$categories[6]['services'];
    array_splice($consulting, 3, 2, [[
        'name_ar' => $consulting[3]['name_ar'].' '.$consulting[4]['name_ar'],
        'source_ref' => 'Sheet1!C59:C60',
    ]]);

    return $categories;
}

it('seeds exactly the seven categories and every service the workbook lists', function () {
    $this->seed(ReferenceDataSeeder::class);

    $seeded = ServiceCategory::query()->with('services')->orderBy('sort_order')->get()->map(fn (ServiceCategory $category): array => [
        'name_ar' => $category->name_ar,
        'name_en' => (string) $category->name_en,
        'source_ref' => $category->source_ref,
        'services' => $category->services->map(fn (CatalogService $service): array => [
            'name_ar' => $service->name_ar,
            'source_ref' => $service->source_ref,
        ])->all(),
    ])->all();

    expect($seeded)->toBe(catalogStatedByWorkbook());
})->skip(! extension_loaded('zip'), 'Reading the workbook needs the PHP zip extension.');

it('joins rows 59 and 60 only because row 60 continues row 59', function () {
    $rows = serviceWorkbookRows();

    expect(trim($rows[59]['C']))->toBe('إدارة التدريب')
        ->and(trim($rows[60]['C']))->toStartWith('و')
        ->and(trim($rows[60]['B'] ?? ''))->toBe('');
})->skip(! extension_loaded('zip'), 'Reading the workbook needs the PHP zip extension.');

it('keeps the same catalog when the reference data is seeded again', function () {
    $this->seed(ReferenceDataSeeder::class);
    $codes = CatalogService::query()->orderBy('id')->pluck('code', 'id')->all();

    $this->seed(ReferenceDataSeeder::class);

    expect(ServiceCategory::query()->count())->toBe(7)
        ->and(CatalogService::query()->count())->toBe(42)
        ->and(CatalogService::query()->orderBy('id')->pluck('code', 'id')->all())->toBe($codes);
});
