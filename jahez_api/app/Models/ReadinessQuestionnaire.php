<?php

namespace App\Models;

use App\Readiness\QuestionnaireShape;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * A version of the digital readiness questionnaire (ADR-018). Source: «إطار تقييم مستوى
 * الجاهزية الرقمية». The current version is the one factories answer; earlier versions
 * stay as they were, so the results recorded against them can be reproduced.
 *
 * @property int $id
 * @property int $version
 * @property string $title_ar
 * @property string|null $title_en
 * @property string $source_ref
 * @property bool|null $is_current
 * @property Carbon|null $published_at
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property int|null $published_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReadinessQuestionnaire extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'version',
        'title_ar',
        'title_en',
        'source_ref',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_current' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * A draft has never been published: IMC administrators may still change it. A
     * published version is frozen, so the results recorded against it stay reproducible.
     */
    public function isDraft(): bool
    {
        return $this->published_at === null && $this->is_current !== true;
    }

    /**
     * The version factories answer now, or null when none is seeded.
     */
    public static function current(): ?self
    {
        return self::query()->where('is_current', true)->first();
    }

    /**
     * @return HasMany<ReadinessPillar, $this>
     */
    public function pillars(): HasMany
    {
        return $this->hasMany(ReadinessPillar::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<ReadinessQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(ReadinessQuestion::class)->orderBy('number');
    }

    /**
     * @return HasMany<ReadinessCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(ReadinessCategory::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<ReadinessAssessment, $this>
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(ReadinessAssessment::class);
    }

    /**
     * Who drafted this version (null for the seeded source version).
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /**
     * The category whose score range contains the total. A total outside every range,
     * or inside more than one, has no valid classification.
     *
     * @throws InvalidArgumentException
     */
    public function categoryForScore(int $totalScore): ReadinessCategory
    {
        $matches = $this->categories()
            ->where('min_score', '<=', $totalScore)
            ->where('max_score', '>=', $totalScore)
            ->get();

        if ($matches->count() !== 1) {
            throw new InvalidArgumentException("No single readiness category of questionnaire version {$this->version} covers a total score of {$totalScore}.");
        }

        return $matches->sole();
    }

    /**
     * Copy this version, with its pillars, questions, choices, categories and roadmap
     * recommendations, into a new unpublished version numbered after the highest one
     * (ADR-018 addendum). The caller runs this in a transaction.
     */
    public function copyAsDraft(): self
    {
        $this->loadMissing(['pillars.questions.choices', 'categories.recommendations.services']);

        $draft = new self;
        $draft->version = (int) self::query()->max('version') + 1;
        $draft->title_ar = $this->title_ar;
        $draft->title_en = $this->title_en;
        $draft->source_ref = "Draft based on version {$this->version}";
        $draft->save();

        foreach ($this->pillars as $pillar) {
            $newPillar = $draft->pillars()->create($pillar->only(['code', 'name_ar', 'name_en', 'sort_order']));
            foreach ($pillar->questions as $question) {
                $newQuestion = $draft->questions()->create([...$question->only(['code', 'number', 'text_ar', 'source_ref']), 'readiness_pillar_id' => $newPillar->id]);
                foreach ($question->choices as $choice) {
                    $newQuestion->choices()->create($choice->only(['code', 'label_ar', 'text_ar', 'points', 'sort_order']));
                }
            }
        }

        foreach ($this->categories as $category) {
            $newCategory = $draft->categories()->create([...$category->only(['name_en', 'name_ar', 'description_ar', 'min_score', 'max_score', 'focus_ar', 'steps_ar', 'sort_order']), 'code' => $category->code]);
            foreach ($category->recommendations as $recommendation) {
                $newRecommendation = $newCategory->recommendations()->create($recommendation->only(['sort_order', 'text_ar', 'source_ref']));
                $newRecommendation->services()->sync($recommendation->services->modelKeys());
            }
        }

        return $draft;
    }

    /**
     * Replace the structure of a draft with the given definition: pillars, questions and
     * choices in the order given, and the ranges and texts of the four categories. Rows
     * are matched by code, so their ids stay stable across edits; rows the definition no
     * longer lists are removed (a draft has no answers). A category given `recommendations`
     * gets exactly those roadmap lines; without them its lines are kept.
     * The caller has validated the definition and runs this in a transaction.
     *
     * @param  array{title_ar: string, title_en?: string|null, pillars: list<array{code: string, name_ar: string, name_en?: string|null, questions: list<array{code: string, text_ar: string, choices: list<array{code: string, label_ar: string, text_ar: string, points: int}>}>}>, categories: list<array{code: string, name_ar: string, name_en: string, description_ar: string, min_score: int, max_score: int, focus_ar: string, steps_ar: string, recommendations?: list<array{text_ar: string, services: list<string>}>}>}  $definition
     */
    public function replaceDefinition(array $definition): void
    {
        if (! $this->isDraft()) {
            throw new LogicException('Only a draft questionnaire version can be changed.');
        }

        $this->title_ar = $definition['title_ar'];
        $this->title_en = $definition['title_en'] ?? null;
        $this->save();

        // Free the question numbers first: they are unique per version, and a reorder
        // would otherwise collide with a number still held by another question.
        $this->questions()->toBase()->update(['number' => DB::raw('number + 10000')]);

        $keptPillarIds = [];
        $keptQuestionIds = [];
        $number = 0;
        foreach ($definition['pillars'] as $pillarPosition => $pillarData) {
            $pillar = $this->pillars()->updateOrCreate(
                ['code' => $pillarData['code']],
                ['name_ar' => $pillarData['name_ar'], 'name_en' => $pillarData['name_en'] ?? null, 'sort_order' => $pillarPosition + 1],
            );
            $keptPillarIds[] = $pillar->id;

            foreach ($pillarData['questions'] as $questionData) {
                $number++;
                $question = $this->questions()->updateOrCreate(
                    ['code' => $questionData['code']],
                    ['readiness_pillar_id' => $pillar->id, 'number' => $number, 'text_ar' => $questionData['text_ar'], 'source_ref' => "Version {$this->version}, question {$number}"],
                );
                $keptQuestionIds[] = $question->id;

                $keptChoiceCodes = [];
                foreach ($questionData['choices'] as $choicePosition => $choiceData) {
                    $question->choices()->updateOrCreate(
                        ['code' => $choiceData['code']],
                        ['label_ar' => $choiceData['label_ar'], 'text_ar' => $choiceData['text_ar'], 'points' => $choiceData['points'], 'sort_order' => $choicePosition + 1],
                    );
                    $keptChoiceCodes[] = $choiceData['code'];
                }
                $question->choices()->whereNotIn('code', $keptChoiceCodes)->delete();
            }
        }

        $removedQuestionIds = $this->questions()->whereKeyNot($keptQuestionIds)->pluck('id');
        ReadinessChoice::query()->whereIn('readiness_question_id', $removedQuestionIds)->delete();
        ReadinessQuestion::query()->whereKey($removedQuestionIds)->delete();
        $this->pillars()->whereKeyNot($keptPillarIds)->delete();

        foreach ($definition['categories'] as $categoryPosition => $categoryData) {
            $category = $this->categories()->where('code', $categoryData['code'])->firstOrFail();
            $category->update([
                'name_ar' => $categoryData['name_ar'],
                'name_en' => $categoryData['name_en'],
                'description_ar' => $categoryData['description_ar'],
                'min_score' => $categoryData['min_score'],
                'max_score' => $categoryData['max_score'],
                'focus_ar' => $categoryData['focus_ar'],
                'steps_ar' => $categoryData['steps_ar'],
                'sort_order' => $categoryPosition + 1,
            ]);

            if (array_key_exists('recommendations', $categoryData)) {
                $this->replaceRecommendations($category, $categoryData['recommendations']);
            }
        }
    }

    /**
     * Replace a draft category's roadmap lines, in the order given, each mapped to the
     * existing catalog services named by code. No catalog service is ever created here.
     *
     * @param  list<array{text_ar: string, services: list<string>}>  $recommendations
     */
    private function replaceRecommendations(ReadinessCategory $category, array $recommendations): void
    {
        // The catalog mapping rows go with their recommendation (cascade).
        $category->recommendations()->delete();

        $serviceIds = CatalogService::query()
            ->whereIn('code', array_merge(...array_map(fn (array $line): array => $line['services'], $recommendations)))
            ->pluck('id', 'code');

        foreach ($recommendations as $position => $line) {
            $recommendation = $category->recommendations()->create([
                'sort_order' => $position + 1,
                'text_ar' => $line['text_ar'],
                'source_ref' => "Version {$this->version}, edited by IMC",
            ]);
            $recommendation->services()->sync(array_values(array_unique(array_map(fn (string $code): int => (int) $serviceIds->get($code), $line['services']))));
        }
    }

    /**
     * Why this version cannot be answered as stored, if anything (ADR-018 addendum 2):
     * the source shape (QuestionnaireShape: five pillars, two questions each, four
     * choices worth 1 to 4 with distinct labels), category ranges covering every total
     * from the lowest to the highest possible score once, without gaps or overlaps, and
     * at least one recommendation per category. Checked again when a draft is published.
     *
     * @return list<string>
     */
    public function definitionProblems(): array
    {
        $problems = [];
        $pillars = $this->pillars()->with('questions.choices')->get();

        if ($pillars->count() !== QuestionnaireShape::PILLARS) {
            $problems[] = 'The questionnaire needs exactly '.QuestionnaireShape::PILLARS.' pillars.';
        }

        foreach ($pillars as $pillar) {
            if ($pillar->questions->count() !== QuestionnaireShape::QUESTIONS_PER_PILLAR) {
                $problems[] = "The {$pillar->code} pillar needs exactly ".QuestionnaireShape::QUESTIONS_PER_PILLAR.' questions.';
            }

            foreach ($pillar->questions as $question) {
                if ($question->choices->count() !== QuestionnaireShape::CHOICES_PER_QUESTION) {
                    $problems[] = "Question {$question->number} needs exactly ".QuestionnaireShape::CHOICES_PER_QUESTION.' choices.';

                    continue;
                }
                if (! QuestionnaireShape::hasSourcePoints(array_values($question->choices->map(fn (ReadinessChoice $choice): int => $choice->points)->all()))) {
                    $problems[] = "The choices of question {$question->number} must be worth 1, 2, 3 and 4 points, each used once.";
                }
                if (! QuestionnaireShape::hasDistinctLabels(array_values($question->choices->map(fn (ReadinessChoice $choice): string => $choice->label_ar)->all()))) {
                    $problems[] = "The choice labels of question {$question->number} must be filled in and different from each other.";
                }
            }
        }

        if ($problems !== []) {
            return $problems;
        }

        $range = $this->scoreRange();
        $expectedMin = $range['min'];
        foreach ($this->categories()->orderBy('min_score')->get() as $category) {
            if ($category->min_score > $category->max_score) {
                $problems[] = "The {$category->code->value} category ends before it starts.";
            } elseif ($category->min_score !== $expectedMin) {
                $problems[] = "The {$category->code->value} category must start at {$expectedMin}, so the ranges have no gap or overlap.";
            }
            $expectedMin = $category->max_score + 1;
        }

        if ($problems === [] && $expectedMin - 1 !== $range['max']) {
            $problems[] = "The highest category must end at {$range['max']}, the highest possible total.";
        }

        foreach ($this->categories()->withCount(['recommendations' => fn ($query) => $query->where('text_ar', '!=', '')])->get() as $category) {
            if ($category->recommendations_count === 0) {
                $problems[] = "The {$category->code->value} category needs at least one recommendation.";
            }
        }

        return $problems;
    }

    /**
     * The lowest and the highest total a complete set of answers can reach: the sums of
     * each question's lowest and highest choice.
     *
     * @return array{min: int, max: int}
     */
    public function scoreRange(): array
    {
        $perQuestion = ReadinessChoice::query()
            ->whereIn('readiness_question_id', $this->questions()->select('id'))
            ->groupBy('readiness_question_id')
            ->selectRaw('MIN(points) AS lowest, MAX(points) AS highest')
            ->toBase()
            ->get();

        return [
            'min' => (int) $perQuestion->sum('lowest'),
            'max' => (int) $perQuestion->sum('highest'),
        ];
    }
}
