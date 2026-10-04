<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ReadinessAssessment;
use App\Models\ReadinessChoice;
use App\Models\ReadinessQuestion;
use App\Models\ReadinessQuestionnaire;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A factory member submits their factory's readiness assessment (ADR-018): one choice
 * for every question of the current questionnaire version, as question and choice ids.
 * Only the ids are read. A score, category or points sent by the client are ignored; the
 * server calculates them from the stored choices.
 */
class StoreReadinessAssessmentRequest extends FormRequest
{
    public const STALE_VERSION = 'The questionnaire has changed. Load the current version and answer it again.';

    public const ANSWER_EVERY_QUESTION = 'Answer every question of the questionnaire exactly once (:size answers).';

    public const QUESTION_ANSWERED_TWICE = 'Each question may be answered only once.';

    public const CHOICE_OF_ANOTHER_QUESTION = 'The selected choice does not belong to this question.';

    private ?ReadinessQuestionnaire $questionnaire = null;

    /**
     * Authorization runs before validation, so a factory the user may not see is
     * reported as not found even when the payload is invalid.
     */
    public function authorize(): Response
    {
        return Gate::inspect('create', [ReadinessAssessment::class, $this->route('factory')]);
    }

    /**
     * The optional `Idempotency-Key` header (as for payments): repeating a submission
     * with the same key returns the assessment already stored instead of a second one.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $questionnaire = $this->questionnaire();

        return [
            'idempotency_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{8,100}$/'],
            'questionnaire_version' => ['required', 'integer', Rule::in([$questionnaire->version])],
            'answers' => ['required', 'list', 'size:'.$questionnaire->questions->count()],
            'answers.*' => ['required', 'array'],
            'answers.*.question_id' => ['required', 'integer', 'distinct', Rule::in($questionnaire->questions->modelKeys())],
            'answers.*.choice_id' => ['required', 'integer'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'questionnaire_version.in' => self::STALE_VERSION,
            'answers.size' => self::ANSWER_EVERY_QUESTION,
            'answers.*.question_id.distinct' => self::QUESTION_ANSWERED_TWICE,
            'idempotency_key.regex' => 'The Idempotency-Key header must be 8 to 100 letters, digits, - or _.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['questionnaire_version', 'answers', 'answers.*'])) {
                    return;
                }

                foreach ((array) $this->input('answers') as $index => $answer) {
                    if ($validator->errors()->hasAny(["answers.{$index}.question_id", "answers.{$index}.choice_id"])) {
                        continue;
                    }

                    if ($this->choiceFor((int) $answer['question_id'], (int) $answer['choice_id']) === null) {
                        $validator->errors()->add("answers.{$index}.choice_id", self::CHOICE_OF_ANOTHER_QUESTION);
                    }
                }
            },
        ];
    }

    /**
     * The current questionnaire version with its questions and choices.
     */
    public function questionnaire(): ReadinessQuestionnaire
    {
        return $this->questionnaire ??= ReadinessQuestionnaire::query()
            ->where('is_current', true)
            ->with('questions.choices')
            ->first()
            ?? throw new ConflictHttpException('No readiness questionnaire is available.');
    }

    /**
     * The validated choices, one per question.
     *
     * @return list<ReadinessChoice>
     */
    public function selectedChoices(): array
    {
        $choices = [];
        foreach ((array) $this->validated('answers') as $answer) {
            $choices[] = $this->choiceFor((int) $answer['question_id'], (int) $answer['choice_id'])
                ?? throw new ConflictHttpException('The questionnaire changed while the assessment was being submitted.');
        }

        return $choices;
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->validated('idempotency_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function choiceFor(int $questionId, int $choiceId): ?ReadinessChoice
    {
        /** @var ReadinessQuestion|null $question */
        $question = $this->questionnaire()->questions->find($questionId);

        return $question?->choices->find($choiceId);
    }
}
