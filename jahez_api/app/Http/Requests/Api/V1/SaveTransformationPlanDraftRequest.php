<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * IMC saves the whole structure of a plan's draft (ADR-025): stages in the order given,
 * each with its services in order, the provider IMC assigns (optional), instructions,
 * internal notes, planned dates and finish-to-start dependencies by service code.
 * `based_on_revision` is the draft revision the editor loaded. The shape is checked here;
 * TransformationPlanDraft::save() checks what needs the whole plan (a service placed
 * twice, dependencies outside the plan, cycles) and the revision.
 *
 * @phpstan-import-type DraftDefinition from \App\TransformationPlans\TransformationPlanDraft
 */
class SaveTransformationPlanDraftRequest extends FormRequest
{
    public const MAX_STAGES = 20;

    public const MAX_ITEMS_PER_STAGE = 30;

    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->route('transformationPlan'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $text = ['sometimes', 'nullable', 'string', 'max:5000'];
        $date = ['sometimes', 'nullable', 'date_format:Y-m-d'];

        return [
            'based_on_revision' => ['required', 'integer', 'min:0'],
            'title' => ['required', 'string', 'max:200'],
            'summary_ar' => $text,
            'change_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'stages' => ['present', 'array', 'max:'.self::MAX_STAGES],
            'stages.*' => ['array:name_ar,objective_ar,description_ar,factory_instructions_ar,internal_notes,planned_start_date,planned_end_date,items'],
            'stages.*.name_ar' => ['required', 'string', 'max:200'],
            'stages.*.objective_ar' => $text,
            'stages.*.description_ar' => $text,
            'stages.*.factory_instructions_ar' => $text,
            'stages.*.internal_notes' => $text,
            'stages.*.planned_start_date' => $date,
            'stages.*.planned_end_date' => $date,
            'stages.*.items' => ['present', 'array', 'max:'.self::MAX_ITEMS_PER_STAGE],
            'stages.*.items.*' => ['array:service,service_provider_id,instructions_ar,internal_notes,planned_start_date,planned_end_date,depends_on'],
            'stages.*.items.*.service' => ['required', 'string', 'max:60'],
            'stages.*.items.*.service_provider_id' => ['sometimes', 'nullable', 'integer', Rule::exists('service_providers', 'id')],
            'stages.*.items.*.instructions_ar' => $text,
            'stages.*.items.*.internal_notes' => $text,
            'stages.*.items.*.planned_start_date' => $date,
            'stages.*.items.*.planned_end_date' => $date,
            'stages.*.items.*.depends_on' => ['sometimes', 'array', 'max:'.self::MAX_ITEMS_PER_STAGE],
            'stages.*.items.*.depends_on.*' => ['string', 'max:60'],
        ];
    }

    /**
     * Planned end dates may not come before their start dates (both are optional).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('stages', []) as $s => $stage) {
                    self::checkDates($validator, is_array($stage) ? $stage : [], "stages.{$s}");

                    foreach (is_array($stage) ? (array) ($stage['items'] ?? []) : [] as $i => $item) {
                        self::checkDates($validator, is_array($item) ? $item : [], "stages.{$s}.items.{$i}");
                    }
                }
            },
        ];
    }

    /**
     * The validated structure, in the shape TransformationPlanDraft::save() reads.
     *
     * @return DraftDefinition
     */
    public function definition(): array
    {
        /** @var DraftDefinition $definition */
        $definition = [
            'title' => $this->string('title')->toString(),
            'summary_ar' => $this->filled('summary_ar') ? $this->string('summary_ar')->toString() : null,
            'change_note' => $this->filled('change_note') ? $this->string('change_note')->toString() : null,
            'stages' => array_values(array_map(fn (array $stage): array => [
                ...$stage,
                'items' => array_values(array_map(fn (array $item): array => [
                    ...$item,
                    'service_provider_id' => isset($item['service_provider_id']) ? (int) $item['service_provider_id'] : null,
                    'depends_on' => array_values($item['depends_on'] ?? []),
                ], $stage['items'] ?? [])),
            ], (array) $this->validated('stages'))),
        ];

        return $definition;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private static function checkDates(Validator $validator, array $entry, string $path): void
    {
        $start = $entry['planned_start_date'] ?? null;
        $end = $entry['planned_end_date'] ?? null;

        if (is_string($start) && is_string($end) && $end < $start && ! $validator->errors()->has("{$path}.planned_end_date")) {
            $validator->errors()->add("{$path}.planned_end_date", 'The planned end date must not be before the planned start date.');
        }
    }

    public function basedOnRevision(): int
    {
        return $this->integer('based_on_revision');
    }
}
