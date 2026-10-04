<?php

use App\Models\CatalogService;
use App\Models\ReadinessCategory;
use App\Models\ReadinessChoice;
use App\Models\ReadinessPillar;
use App\Models\ReadinessQuestion;
use App\Models\ReadinessQuestionnaire;
use App\Models\ReadinessRecommendation;
use Database\Seeders\ReferenceDataSeeder;

/**
 * The body of the readiness framework document, read from the .docx itself so the seeded
 * questionnaire is checked against the source and not against a copy: each paragraph as
 * [style, text] and each table as a list of rows of cell texts, all trimmed.
 *
 * @return list<array{type: 'paragraph', style: string|null, text: string}|array{type: 'table', rows: list<list<string>>}>
 */
function readinessSourceBlocks(): array
{
    $archive = new ZipArchive;
    $archive->open(base_path('docs/إطار تقييم مستوى الجاهزية الرقمية.docx'));
    $document = new DOMDocument;
    $document->loadXML((string) $archive->getFromName('word/document.xml'));
    $archive->close();

    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $textOf = fn (DOMNode $node): string => trim(implode('', array_map(
        fn (DOMNode $run): string => $run->textContent,
        iterator_to_array($xpath->query('.//w:t', $node)),
    )));

    $blocks = [];
    foreach ($xpath->query('/w:document/w:body/*') as $node) {
        if ($node->localName === 'p') {
            $blocks[] = ['type' => 'paragraph', 'style' => $xpath->query('./w:pPr/w:pStyle/@w:val', $node)->item(0)?->nodeValue, 'text' => $textOf($node)];
        } elseif ($node->localName === 'tbl') {
            $rows = [];
            foreach ($xpath->query('./w:tr', $node) as $row) {
                $rows[] = array_map($textOf, iterator_to_array($xpath->query('./w:tc', $row)));
            }
            $blocks[] = ['type' => 'table', 'rows' => $rows];
        }
    }

    return $blocks;
}

/**
 * The questionnaire as the document states it: §4 pillar headings «المحور …: name (English)»,
 * question headings «سN: text» and choice lines «X) text (N نقطة)».
 *
 * @return list<array{name_ar: string, name_en: string, questions: list<array{number: int, text_ar: string, choices: list<array{label_ar: string, text_ar: string, points: int}>}>}>
 */
function readinessQuestionnaireStatedBySource(): array
{
    $pillars = [];
    foreach (readinessSourceBlocks() as $block) {
        if ($block['type'] !== 'paragraph') {
            continue;
        }

        if ($block['style'] === 'Heading3' && preg_match('/^المحور \S+: (.+?) \((.+)\)$/u', $block['text'], $pillar) === 1) {
            $pillars[] = ['name_ar' => $pillar[1], 'name_en' => $pillar[2], 'questions' => []];
        } elseif ($block['style'] === 'Heading4' && preg_match('/^س(\d+): (.+)$/u', $block['text'], $question) === 1) {
            $pillars[array_key_last($pillars)]['questions'][] = ['number' => (int) $question[1], 'text_ar' => $question[2], 'choices' => []];
        } elseif (preg_match('/^([أبجد])\) (.+) \((\d) (?:نقطة|نقاط)\)$/u', $block['text'], $choice) === 1) {
            $questions = &$pillars[array_key_last($pillars)]['questions'];
            $questions[array_key_last($questions)]['choices'][] = ['label_ar' => $choice[1], 'text_ar' => $choice[2], 'points' => (int) $choice[3]];
            unset($questions);
        }
    }

    return $pillars;
}

/**
 * The categories as the document states them: the §3 table (name, description, range)
 * and the §5 roadmap (focus, steps and the service table that follows them).
 *
 * @return list<array{name_en: string, name_ar: string, description_ar: string, min_score: int, max_score: int, focus_ar: string, steps_ar: string, lines: list<string>}>
 */
function readinessCategoriesStatedBySource(): array
{
    $blocks = readinessSourceBlocks();
    $tables = array_values(array_filter($blocks, fn (array $block): bool => $block['type'] === 'table'));

    $categories = [];
    foreach (array_slice($tables[0]['rows'], 1) as $row) {
        preg_match('/^(.+?) \((.+)\)$/u', $row[0], $name);
        preg_match('/^(\d+)\s*-\s*(\d+)$/u', $row[2], $range);
        $categories[] = [
            'name_en' => $name[1],
            'name_ar' => $name[2],
            'description_ar' => $row[1],
            'min_score' => (int) $range[1],
            'max_score' => (int) $range[2],
        ];
    }

    $roadmaps = [];
    foreach ($blocks as $block) {
        if ($block['type'] === 'paragraph' && preg_match('/^(B4 Automation|Basic|Advanced|Smart) \(\d+ - \d+ نقطة\):$/u', $block['text'], $heading) === 1) {
            $roadmaps[$heading[1]] = ['focus_ar' => '', 'steps_ar' => '', 'lines' => []];
            $current = $heading[1];
        } elseif (isset($current) && $block['type'] === 'paragraph' && preg_match('/^(التركيز|الخطوات): (.+)$/u', $block['text'], $part) === 1) {
            $roadmaps[$current][$part[1] === 'التركيز' ? 'focus_ar' : 'steps_ar'] = $part[2];
        } elseif (isset($current) && $block['type'] === 'table') {
            $roadmaps[$current]['lines'] = array_map(fn (array $row): string => $row[0], $block['rows']);
        }
    }

    return array_map(fn (array $category): array => [...$category, ...$roadmaps[$category['name_en']]], $categories);
}

/**
 * A recommendation line compared with a catalog service name: trimmed, and without an
 * unbalanced closing parenthesis at the end (the source prints «(ERP).)»).
 */
function readinessComparableText(string $text): string
{
    $text = trim($text);

    return substr_count($text, '(') < substr_count($text, ')') && str_ends_with($text, ')') ? substr($text, 0, -1) : $text;
}

it('seeds the five pillars and ten questions with their exact source text and choice points', function () {
    $this->seed(ReferenceDataSeeder::class);

    $seeded = ReadinessQuestionnaire::query()->where('is_current', true)->sole()->pillars()->with('questions.choices')->get()
        ->map(fn (ReadinessPillar $pillar): array => [
            'name_ar' => $pillar->name_ar,
            'name_en' => $pillar->name_en,
            'questions' => $pillar->questions->map(fn (ReadinessQuestion $question): array => [
                'number' => $question->number,
                'text_ar' => $question->text_ar,
                'choices' => $question->choices->map(fn (ReadinessChoice $choice): array => [
                    'label_ar' => $choice->label_ar,
                    'text_ar' => $choice->text_ar,
                    'points' => $choice->points,
                ])->all(),
            ])->all(),
        ])->all();

    expect($seeded)->toBe(readinessQuestionnaireStatedBySource());
})->skip(! extension_loaded('zip'), 'Reading the document needs the PHP zip extension.');

it('seeds the four categories with the source names, descriptions, score ranges and roadmaps', function () {
    $this->seed(ReferenceDataSeeder::class);

    $seeded = ReadinessCategory::query()->with('recommendations')->orderBy('sort_order')->get()
        ->map(fn (ReadinessCategory $category): array => [
            'name_en' => $category->name_en,
            'name_ar' => $category->name_ar,
            'description_ar' => $category->description_ar,
            'min_score' => $category->min_score,
            'max_score' => $category->max_score,
            'focus_ar' => $category->focus_ar,
            'steps_ar' => $category->steps_ar,
            'lines' => $category->recommendations->pluck('text_ar')->all(),
        ])->all();

    $statedBySource = array_map(
        fn (array $category): array => [...$category, 'lines' => array_values(array_unique($category['lines']))],
        readinessCategoriesStatedBySource(),
    );

    expect($seeded)->toBe($statedBySource);
})->skip(! extension_loaded('zip'), 'Reading the document needs the PHP zip extension.');

it('seeds the repeated Basic line once only because the source prints it twice', function () {
    $basic = collect(readinessCategoriesStatedBySource())->firstWhere('name_en', 'Basic');

    expect($basic['lines'])->toHaveCount(9)
        ->and(array_count_values($basic['lines'])['بناء القدرات والتوعية والتدريب في مجال الأمن السيبراني.'])->toBe(2)
        ->and(array_unique($basic['lines']))->toHaveCount(8);
})->skip(! extension_loaded('zip'), 'Reading the document needs the PHP zip extension.');

it('states the choice points and the total range the seeder uses', function () {
    $texts = array_column(array_filter(readinessSourceBlocks(), fn (array $block): bool => $block['type'] === 'paragraph'), 'text');

    expect($texts)->toContain('(أ): 1 نقطة', '(ب): 2 نقطة', '(ج): 3 نقاط', '(د): 4 نقاط', 'النطاق الإجمالي للدرجات: من 10 إلى 40 نقطة.');
})->skip(! extension_loaded('zip'), 'Reading the document needs the PHP zip extension.');

it('maps each recommendation to exactly the catalog services that carry its text', function () {
    $this->seed(ReferenceDataSeeder::class);
    $catalog = CatalogService::query()->get();

    $recommendations = ReadinessRecommendation::query()->with('services')->orderBy('readiness_category_id')->orderBy('sort_order')->get();

    foreach ($recommendations as $recommendation) {
        $text = readinessComparableText($recommendation->text_ar);
        // «إدارة التدريب» is workbook row 59, joined with row 60 into one catalog service
        // (owner decision 2026-10-03); every other line must match a name exactly.
        $expected = $catalog->filter(fn (CatalogService $service): bool => $text === 'إدارة التدريب'
            ? str_starts_with($service->name_ar, 'إدارة التدريب')
            : readinessComparableText($service->name_ar) === $text);

        expect($recommendation->services->pluck('code')->sort()->values()->all())
            ->toBe($expected->pluck('code')->sort()->values()->all(), "Mapping of «{$recommendation->text_ar}»");
    }
});

it('leaves unmapped exactly the lines the catalog does not offer', function () {
    $this->seed(ReferenceDataSeeder::class);

    $unmapped = ReadinessRecommendation::query()->doesntHave('services')->with('category')->orderBy('readiness_category_id')->orderBy('sort_order')->get()
        ->map(fn (ReadinessRecommendation $recommendation): array => [$recommendation->category->code->value, $recommendation->text_ar])
        ->all();

    expect($unmapped)->toBe([
        ['b4_automation', 'الموارد البشرية'],
        ['b4_automation', 'خدمات الإنتاج ( 5s- lean – الجودة )'],
        ['b4_automation', 'تقييم البنية التحتية'],
        ['basic', 'تقييم البنية التحتية'],
    ]);
});
