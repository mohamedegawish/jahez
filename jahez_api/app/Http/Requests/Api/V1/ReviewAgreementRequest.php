<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AgreementReviewStatus;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * IMC's decision on an agreement (ADR-020): approve, or reject with a reason.
 */
class ReviewAgreementRequest extends FormRequest
{
    /**
     * Authorization runs before validation, so an agreement the user may not see is
     * reported as not found even when the payload is invalid.
     */
    public function authorize(): Response
    {
        return Gate::inspect('review', $this->route('agreement'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::enum(AgreementReviewStatus::class)->except([AgreementReviewStatus::Pending])],
            'reason' => [
                Rule::requiredIf(fn (): bool => $this->input('decision') === AgreementReviewStatus::Rejected->value),
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    public function decision(): AgreementReviewStatus
    {
        return AgreementReviewStatus::from($this->string('decision')->toString());
    }

    public function reason(): ?string
    {
        return $this->filled('reason') ? $this->string('reason')->toString() : null;
    }
}
