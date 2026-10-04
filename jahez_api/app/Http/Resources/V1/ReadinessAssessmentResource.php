<?php

namespace App\Http\Resources\V1;

use App\Models\ReadinessAssessment;
use App\Models\ReadinessAssessmentAnswer;
use App\Models\ReadinessChoice;
use App\Models\ReadinessPillar;
use App\Models\ReadinessQuestion;
use App\Models\ReadinessQuestionnaire;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A completed digital readiness assessment (ADR-018). The total, the category and the
 * points of each answer are those recorded at submission, never recalculated.
 *
 * Summary: expects questionnaire, category and submittedBy to be loaded. The full result
 * (pillar breakdown, answers, roadmap) also needs answers,
 * questionnaire.pillars.questions.choices and category.recommendations.services.category.
 *
 * @mixin ReadinessAssessment
 */
class ReadinessAssessmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $questionnaire = $this->questionnaire;

        return [
            'id' => $this->id,
            'factory_id' => $this->factory_id,
            // Only in the IMC list across factories (ADR-021).
            'factory' => $this->whenLoaded('industrialFactory', fn (): ?array => $this->industrialFactory === null ? null : [
                'id' => $this->industrialFactory->id,
                'name' => $this->industrialFactory->name,
            ]),
            'questionnaire_version' => $questionnaire?->version,
            'total_score' => $this->total_score,
            'category' => $this->category === null ? null : new ReadinessCategoryResource($this->category),
            ...($questionnaire !== null && $this->relationLoaded('answers') ? $this->result($questionnaire) : []),
            'submitted_by' => $this->submittedBy === null ? null : ['id' => $this->submittedBy->id, 'name' => $this->submittedBy->name],
            'completed_at' => $this->completed_at->toIso8601ZuluString(),
        ];
    }

    /**
     * The score range, the score per pillar and the answers, from the recorded points.
     *
     * @return array<string, mixed>
     */
    private function result(ReadinessQuestionnaire $questionnaire): array
    {
        $pillars = $questionnaire->pillars;
        $questions = $pillars->flatMap(fn (ReadinessPillar $pillar) => $pillar->questions)->keyBy('id');
        $choices = $questions->flatMap(fn (ReadinessQuestion $question) => $question->choices)->keyBy('id');
        $pointsByQuestion = $this->answers->pluck('points', 'readiness_question_id');

        return [
            'min_score' => (int) $questions->sum(fn (ReadinessQuestion $question): int => (int) $question->choices->min('points')),
            'max_score' => (int) $questions->sum(fn (ReadinessQuestion $question): int => (int) $question->choices->max('points')),
            'pillars' => $pillars->map(fn (ReadinessPillar $pillar): array => [
                'code' => $pillar->code,
                'name_ar' => $pillar->name_ar,
                'name_en' => $pillar->name_en,
                'score' => (int) $pillar->questions->sum(fn (ReadinessQuestion $question): int => (int) $pointsByQuestion->get($question->id, 0)),
                'max_score' => (int) $pillar->questions->sum(fn (ReadinessQuestion $question): int => (int) $question->choices->max('points')),
            ])->all(),
            'answers' => $this->answers
                ->sortBy(fn (ReadinessAssessmentAnswer $answer): int => $questions->get($answer->readiness_question_id)->number ?? 0)
                ->map(function (ReadinessAssessmentAnswer $answer) use ($questions, $choices): array {
                    /** @var ReadinessQuestion|null $question */
                    $question = $questions->get($answer->readiness_question_id);
                    /** @var ReadinessChoice|null $choice */
                    $choice = $choices->get($answer->readiness_choice_id);

                    // The text the answer was given with; the version's current text only
                    // for answers stored before the snapshot existed.
                    return [
                        'question_id' => $answer->readiness_question_id,
                        'question_code' => $question?->code,
                        'question_number' => $question?->number,
                        'question_text_ar' => $answer->question_text_ar ?? $question?->text_ar,
                        'choice_id' => $answer->readiness_choice_id,
                        'choice_code' => $choice?->code,
                        'choice_label_ar' => $answer->choice_label_ar ?? $choice?->label_ar,
                        'choice_text_ar' => $answer->choice_text_ar ?? $choice?->text_ar,
                        'points' => $answer->points,
                    ];
                })->values()->all(),
        ];
    }
}
