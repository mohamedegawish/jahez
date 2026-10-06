<?php

namespace App\Http\Resources\V1;

use App\Models\ReadinessChoice;
use App\Models\ReadinessPillar;
use App\Models\ReadinessQuestion;
use App\Models\ReadinessQuestionnaire;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The digital readiness questionnaire a factory answers (ADR-018): the questions grouped
 * by pillar, each with its four choices and the points the source prints for them, and
 * the categories with their score ranges and roadmaps. Clients submit the question and
 * choice ids; the server calculates the score. Expects pillars.questions.choices and
 * categories.recommendations.services.category to be loaded.
 *
 * The points, the score range and the category ranges go to IMC administrators only
 * (ReadinessAssessmentResource::showsScores()): a factory answers without seeing what
 * each choice is worth and is shown its level, never a score (ADR-026).
 *
 * @mixin ReadinessQuestionnaire
 */
class ReadinessQuestionnaireResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $questions = $this->pillars->flatMap(fn (ReadinessPillar $pillar) => $pillar->questions);
        $withScores = ReadinessAssessmentResource::showsScores($request);

        return [
            'version' => $this->version,
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en,
            ...($withScores ? [
                'min_score' => (int) $questions->sum(fn (ReadinessQuestion $question): int => (int) $question->choices->min('points')),
                'max_score' => (int) $questions->sum(fn (ReadinessQuestion $question): int => (int) $question->choices->max('points')),
            ] : []),
            'pillars' => $this->pillars->map(fn (ReadinessPillar $pillar): array => [
                'code' => $pillar->code,
                'name_ar' => $pillar->name_ar,
                'name_en' => $pillar->name_en,
                'questions' => $pillar->questions->map(fn (ReadinessQuestion $question): array => [
                    'id' => $question->id,
                    'code' => $question->code,
                    'number' => $question->number,
                    'text_ar' => $question->text_ar,
                    'choices' => $question->choices->map(fn (ReadinessChoice $choice): array => [
                        'id' => $choice->id,
                        'code' => $choice->code,
                        'label_ar' => $choice->label_ar,
                        'text_ar' => $choice->text_ar,
                        ...($withScores ? ['points' => $choice->points] : []),
                    ])->all(),
                ])->all(),
            ])->all(),
            'categories' => ReadinessCategoryResource::collection($this->categories),
        ];
    }
}
