<?php

namespace App\Http\Requests\Api\V1;

use App\Models\EvaluationCriterion;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * An IMC evaluation of a provider against the current DOC §6 criteria (OQ-13 interim).
 * Every criterion needs a written assessment. A score per criterion is required when
 * the owner has approved a scale (config jahez.providers.evaluation.scale_max) and
 * refused while none is approved, so no unapproved number is ever stored.
 */
class StoreProviderEvaluationRequest extends FormRequest
{
    /**
     * @var Collection<int, EvaluationCriterion>|null
     */
    private ?Collection $currentCriteria = null;

    public function authorize(): Response
    {
        return Gate::inspect('evaluate', $this->route('serviceProvider'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $codes = $this->criteria()->pluck('code')->all();
        $scaleMax = self::scaleMax();

        $rules = [
            'summary' => ['required', 'string', 'max:5000'],
            'evaluated_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'criteria' => ['required', 'array:'.implode(',', $codes), 'size:'.count($codes)],
        ];

        foreach ($codes as $code) {
            $rules["criteria.{$code}"] = ['required', 'array:score,note'];
            $rules["criteria.{$code}.note"] = ['required', 'string', 'max:2000'];
            $rules["criteria.{$code}.score"] = $scaleMax === null
                ? ['prohibited']
                : ['required', 'decimal:0,2', 'min:0', 'max:'.$scaleMax];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'criteria.*.score.prohibited' => 'No evaluation scale is approved yet (OQ-13): record a written assessment without a score.',
            'criteria.size' => 'Assess every criterion of the current evaluation matrix.',
        ];
    }

    /**
     * The criteria of the latest version of the evaluation matrix, in source order.
     *
     * @return Collection<int, EvaluationCriterion>
     */
    public function criteria(): Collection
    {
        return $this->currentCriteria ??= EvaluationCriterion::query()
            ->where('version', (int) EvaluationCriterion::query()->max('version'))
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * The owner-approved highest score per criterion, or null while none is approved.
     */
    public static function scaleMax(): ?int
    {
        $scaleMax = config('jahez.providers.evaluation.scale_max');

        return is_int($scaleMax) && $scaleMax > 0 ? $scaleMax : null;
    }

    /**
     * The owner-approved pass mark out of 100 ("60" or "62.50"), or null while none is
     * approved or the configured value is not a valid mark.
     */
    public static function passMark(): ?string
    {
        $passMark = config('jahez.providers.evaluation.pass_mark');

        if (! is_scalar($passMark) || preg_match('/^\d{1,3}(\.\d{1,2})?$/', (string) $passMark) !== 1 || (float) $passMark > 100) {
            return null;
        }

        return (string) $passMark;
    }
}
