<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ReadinessCategoryCode;
use App\Models\ReadinessPillar;
use App\Models\ReadinessQuestion;
use App\Models\ReadinessQuestionnaire;
use App\Readiness\QuestionnaireShape;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The full definition of a draft questionnaire version (ADR-018 addendum and addendum 2):
 * its pillars with their questions and choices in display order, the texts and score
 * ranges of the four readiness categories and, optionally, each category's roadmap
 * recommendations.
 *
 * The shape is the source document's (QuestionnaireShape): the draft's five pillars, each
 * with its two questions, each with its four choices worth 1, 2, 3 and 4 points. Rows are
 * matched by code, so they may be reordered and reworded but not added, removed or moved
 * to another pillar. The category ranges must cover every total from 10 to 40 exactly
 * once, and the categories themselves are fixed (ReadinessCategoryCode).
 */
class UpdateReadinessQuestionnaireRequest extends FormRequest
{
    private const CODE_PATTERN = '/^[a-z0-9_]+$/';

    public const FIXED_PILLARS = 'The pillars of a version are fixed: keep the same five pillar codes (they may be reordered).';

    public const FIXED_QUESTIONS = 'Each pillar keeps its own two questions (they may be reordered or reworded).';

    public const FIXED_CHOICES = 'Each question keeps its own four choices (they may be reordered or reworded).';

    public const SOURCE_POINTS = 'The four choices of a question are worth 1, 2, 3 and 4 points, each used once.';

    public const DISTINCT_LABELS = 'The choice labels of a question must be filled in and different from each other.';

    public function authorize(): bool
    {
        return Gate::allows('manage', ReadinessQuestionnaire::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pillars' => ['required', 'list', 'size:'.QuestionnaireShape::PILLARS],
            'pillars.*' => ['required', 'array'],
            'pillars.*.code' => ['required', 'string', 'max:50', 'regex:'.self::CODE_PATTERN, 'distinct'],
            'pillars.*.name_ar' => ['required', 'string', 'max:255'],
            'pillars.*.name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pillars.*.questions' => ['required', 'list', 'size:'.QuestionnaireShape::QUESTIONS_PER_PILLAR],
            'pillars.*.questions.*' => ['required', 'array'],
            'pillars.*.questions.*.code' => ['required', 'string', 'max:20', 'regex:'.self::CODE_PATTERN],
            'pillars.*.questions.*.text_ar' => ['required', 'string', 'max:2000'],
            'pillars.*.questions.*.choices' => ['required', 'list', 'size:'.QuestionnaireShape::CHOICES_PER_QUESTION],
            'pillars.*.questions.*.choices.*' => ['required', 'array'],
            'pillars.*.questions.*.choices.*.code' => ['required', 'string', 'max:10', 'regex:'.self::CODE_PATTERN],
            'pillars.*.questions.*.choices.*.label_ar' => ['required', 'string', 'max:10'],
            'pillars.*.questions.*.choices.*.text_ar' => ['required', 'string', 'max:2000'],
            'pillars.*.questions.*.choices.*.points' => ['required', 'integer', 'between:'.min(QuestionnaireShape::POINTS).','.max(QuestionnaireShape::POINTS)],
            'categories' => ['required', 'list', 'size:'.count(ReadinessCategoryCode::cases())],
            'categories.*' => ['required', 'array'],
            'categories.*.code' => ['required', 'string', Rule::enum(ReadinessCategoryCode::class), 'distinct'],
            'categories.*.name_ar' => ['required', 'string', 'max:255'],
            'categories.*.name_en' => ['required', 'string', 'max:255'],
            'categories.*.description_ar' => ['required', 'string', 'max:2000'],
            'categories.*.min_score' => ['required', 'integer', 'min:0', 'max:2000'],
            'categories.*.max_score' => ['required', 'integer', 'min:0', 'max:2000'],
            'categories.*.focus_ar' => ['required', 'string', 'max:2000'],
            'categories.*.steps_ar' => ['required', 'string', 'max:4000'],
            // Optional: without it the roadmap recommendations stay as they are.
            'categories.*.recommendations' => ['sometimes', 'list', 'min:1', 'max:'.QuestionnaireShape::MAX_RECOMMENDATIONS],
            'categories.*.recommendations.*' => ['required', 'array'],
            'categories.*.recommendations.*.text_ar' => ['required', 'string', 'max:500'],
            // A line may map to no catalog service (OQ-42); mapped services must exist.
            'categories.*.recommendations.*.services' => ['present', 'list', 'max:10'],
            'categories.*.recommendations.*.services.*' => ['required', 'string', Rule::exists('catalog_services', 'code')],
        ];
    }

    /**
     * Question codes are unique across the version, choice codes within their question;
     * the pillars, questions and choices are the draft's own; the points and labels are
     * the source scale; and the category ranges must tile the possible totals.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var list<array{code: string, questions: list<array{code: string, choices: list<array{code: string, label_ar: string, points: int}>}>}> $pillars */
                $pillars = (array) $this->input('pillars');
                $this->checkAgainstDraft($validator, $pillars);

                $questionCodes = [];
                $lowest = 0;
                $highest = 0;
                foreach ($pillars as $pillarIndex => $pillar) {
                    foreach ($pillar['questions'] as $questionIndex => $question) {
                        $path = "pillars.{$pillarIndex}.questions.{$questionIndex}";
                        if (in_array($question['code'], $questionCodes, true)) {
                            $validator->errors()->add("{$path}.code", 'Each question code may appear only once in the questionnaire.');
                        }
                        $questionCodes[] = $question['code'];

                        $choiceCodes = array_column($question['choices'], 'code');
                        if (count($choiceCodes) !== count(array_unique($choiceCodes))) {
                            $validator->errors()->add("{$path}.choices", 'Each choice code may appear only once in a question.');
                        }

                        $points = array_map('intval', array_column($question['choices'], 'points'));
                        if ($points === []) {
                            continue;
                        }
                        if (! QuestionnaireShape::hasSourcePoints($points)) {
                            $validator->errors()->add("{$path}.choices", self::SOURCE_POINTS);
                        }
                        if (! QuestionnaireShape::hasDistinctLabels(array_map('strval', array_column($question['choices'], 'label_ar')))) {
                            $validator->errors()->add("{$path}.choices", self::DISTINCT_LABELS);
                        }

                        $lowest += min($points);
                        $highest += max($points);
                    }
                }

                /** @var list<array{code: string, min_score: int, max_score: int}> $categories */
                $categories = (array) $this->input('categories');
                usort($categories, fn (array $a, array $b): int => (int) $a['min_score'] <=> (int) $b['min_score']);
                $expectedMin = $lowest;
                foreach ($categories as $category) {
                    if ((int) $category['min_score'] > (int) $category['max_score']) {
                        $validator->errors()->add('categories', "The {$category['code']} range ends before it starts.");

                        return;
                    }
                    if ((int) $category['min_score'] !== $expectedMin) {
                        $validator->errors()->add('categories', "The category ranges must cover every total from {$lowest} to {$highest} once: the {$category['code']} range must start at {$expectedMin}.");

                        return;
                    }
                    $expectedMin = (int) $category['max_score'] + 1;
                }

                if ($expectedMin - 1 !== $highest) {
                    $validator->errors()->add('categories', "The highest category range must end at {$highest}, the highest possible total.");
                }
            },
        ];
    }

    /**
     * The definition may reorder and reword the draft's rows but not add, remove or move
     * them: the same pillar codes, each pillar's own question codes, each question's own
     * choice codes.
     *
     * @param  list<array{code: string, questions: list<array{code: string, choices: list<array{code: string}>}>}>  $pillars
     */
    private function checkAgainstDraft(Validator $validator, array $pillars): void
    {
        $version = $this->route('readinessQuestionnaire');
        if (! $version instanceof ReadinessQuestionnaire) {
            return;
        }

        $stored = $version->pillars()->with('questions.choices')->get()->keyBy('code');

        if (! self::sameCodes(array_column($pillars, 'code'), $stored->keys()->all())) {
            $validator->errors()->add('pillars', self::FIXED_PILLARS);

            return;
        }

        foreach ($pillars as $pillarIndex => $pillar) {
            /** @var ReadinessPillar $storedPillar */
            $storedPillar = $stored->get($pillar['code']);
            $storedQuestions = $storedPillar->questions->keyBy('code');

            if (! self::sameCodes(array_column($pillar['questions'], 'code'), $storedQuestions->keys()->all())) {
                $validator->errors()->add("pillars.{$pillarIndex}.questions", self::FIXED_QUESTIONS);

                continue;
            }

            foreach ($pillar['questions'] as $questionIndex => $question) {
                /** @var ReadinessQuestion $storedQuestion */
                $storedQuestion = $storedQuestions->get($question['code']);
                if (! self::sameCodes(array_column($question['choices'], 'code'), $storedQuestion->choices->pluck('code')->all())) {
                    $validator->errors()->add("pillars.{$pillarIndex}.questions.{$questionIndex}.choices", self::FIXED_CHOICES);
                }
            }
        }
    }

    /**
     * @param  array<mixed>  $given
     * @param  array<mixed>  $stored
     */
    private static function sameCodes(array $given, array $stored): bool
    {
        $given = array_map('strval', $given);
        $stored = array_map('strval', $stored);
        sort($given);
        sort($stored);

        return $given === $stored;
    }

    /**
     * @return array{title_ar: string, title_en?: string|null, pillars: list<array{code: string, name_ar: string, name_en?: string|null, questions: list<array{code: string, text_ar: string, choices: list<array{code: string, label_ar: string, text_ar: string, points: int}>}>}>, categories: list<array{code: string, name_ar: string, name_en: string, description_ar: string, min_score: int, max_score: int, focus_ar: string, steps_ar: string, recommendations?: list<array{text_ar: string, services: list<string>}>}>}
     */
    public function definition(): array
    {
        /** @phpstan-ignore return.type (shape guaranteed by the rules above) */
        return $this->validated();
    }
}
