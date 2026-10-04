<?php

namespace App\Readiness;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Factory;
use App\Models\ReadinessAssessment;
use App\Models\ReadinessAssessmentAnswer;
use App\Models\ReadinessChoice;
use App\Models\ReadinessQuestion;
use App\Models\ReadinessQuestionnaire;
use App\Models\User;
use App\Notifications\MarketplaceNotifications;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Records a factory's digital readiness assessment (ADR-018): the only place that scores
 * and classifies one. The total is the sum of the points stored for the selected choices,
 * and the category is the one of the answered version whose range contains it. The API
 * and the demo seeder both go through here, so no assessment is stored any other way.
 */
class ReadinessAssessmentRecorder
{
    /**
     * Stores the assessment, its answers (with the points and the text each was worth at
     * submission), the audit entry and the factory's notification in one transaction, so a
     * failure leaves nothing behind.
     *
     * With an idempotency key, a repeated submission returns the assessment already stored
     * under that key instead of recording a second one. The factory row is locked, so two
     * copies sent at once are stored once.
     *
     * @param  list<ReadinessChoice>  $choices  one choice for every question of the version
     * @return array{assessment: ReadinessAssessment, created: bool}
     *
     * @throws InvalidArgumentException when the choices do not answer every question once
     * @throws LogicException when the version is not the one factories answer now
     */
    public function record(Factory $factory, ReadinessQuestionnaire $questionnaire, array $choices, User $actor, ?string $idempotencyKey = null): array
    {
        if ($questionnaire->is_current !== true) {
            throw new LogicException("Questionnaire version {$questionnaire->version} is not the current version; assessments answer the current version only.");
        }

        $questions = $questionnaire->loadMissing('questions')->questions->keyBy('id');
        $this->ensureEveryQuestionAnsweredOnce(array_values($questionnaire->questions->map(fn (ReadinessQuestion $question): int => $question->id)->all()), $choices);

        $created = true;

        $assessment = DB::transaction(function () use ($factory, $questionnaire, $choices, $questions, $actor, $idempotencyKey, &$created): ReadinessAssessment {
            if ($idempotencyKey !== null) {
                Factory::query()->lockForUpdate()->findOrFail($factory->id);
                $existing = $factory->readinessAssessments()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    $created = false;

                    return $existing;
                }
            }

            $totalScore = array_sum(array_map(fn (ReadinessChoice $choice): int => $choice->points, $choices));
            $category = $questionnaire->categoryForScore($totalScore);

            $assessment = new ReadinessAssessment;
            $assessment->factory_id = $factory->id;
            $assessment->readiness_questionnaire_id = $questionnaire->id;
            $assessment->readiness_category_id = $category->id;
            $assessment->total_score = $totalScore;
            $assessment->submitted_by_user_id = $actor->id;
            $assessment->idempotency_key = $idempotencyKey;
            $assessment->completed_at = now();
            $assessment->save();

            foreach ($choices as $choice) {
                /** @var ReadinessQuestion $question */
                $question = $questions->get($choice->readiness_question_id);

                $answer = new ReadinessAssessmentAnswer;
                $answer->readiness_assessment_id = $assessment->id;
                $answer->readiness_question_id = $choice->readiness_question_id;
                $answer->readiness_choice_id = $choice->id;
                $answer->points = $choice->points;
                $answer->question_text_ar = $question->text_ar;
                $answer->choice_label_ar = $choice->label_ar;
                $answer->choice_text_ar = $choice->text_ar;
                $answer->save();
            }

            AuditLog::record(AuditEvent::ReadinessAssessmentCompleted, $actor, $factory, [
                'assessment_id' => $assessment->id,
                'questionnaire_version' => $questionnaire->version,
                'total_score' => $totalScore,
                'category' => $category->code->value,
            ]);
            MarketplaceNotifications::readinessAssessmentCompleted($factory->id, $assessment->id);

            return $assessment;
        });

        return ['assessment' => $assessment, 'created' => $created];
    }

    /**
     * @param  list<int>  $questionIds
     * @param  list<ReadinessChoice>  $choices
     */
    private function ensureEveryQuestionAnsweredOnce(array $questionIds, array $choices): void
    {
        $answered = array_map(fn (ReadinessChoice $choice): int => $choice->readiness_question_id, $choices);
        sort($answered);
        sort($questionIds);

        if ($answered !== $questionIds) {
            throw new InvalidArgumentException('An assessment answers every question of the questionnaire version exactly once.');
        }
    }
}
