<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\FactoryApprovalStatus;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * An IMC decision on a factory's account and profile (ADR-021): approve, reject, ask for
 * corrections or suspend. Everything but approval needs a reason. The decision never
 * touches the factory's readiness assessments, score or category.
 */
class ApproveFactoryRequest extends FormRequest
{
    /**
     * Authorization runs before validation, so a factory the user may not see is
     * reported as not found even when the payload is invalid.
     */
    public function authorize(): Response
    {
        return Gate::inspect('approve', $this->route('factory'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::enum(FactoryApprovalStatus::class)->except([FactoryApprovalStatus::Pending])],
            'reason' => [
                Rule::requiredIf(fn (): bool => FactoryApprovalStatus::tryFrom($this->string('decision')->toString())?->requiresReason() ?? false),
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    public function decision(): FactoryApprovalStatus
    {
        return FactoryApprovalStatus::from($this->string('decision')->toString());
    }

    public function reason(): ?string
    {
        return $this->filled('reason') ? $this->string('reason')->toString() : null;
    }
}
