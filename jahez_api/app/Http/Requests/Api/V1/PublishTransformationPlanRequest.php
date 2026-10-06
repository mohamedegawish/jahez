<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * IMC publishes a plan's draft (ADR-025). From version 2 a change note is required; it
 * may also have been saved with the draft.
 */
class PublishTransformationPlanRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->route('transformationPlan'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'change_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function changeNote(): ?string
    {
        return $this->filled('change_note') ? $this->string('change_note')->toString() : null;
    }
}
