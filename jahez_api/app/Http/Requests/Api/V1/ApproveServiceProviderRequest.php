<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProviderApprovalStatus;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * An IMC decision on a provider: approve, reject or suspend (ADR-014). The decision is
 * manual; rejecting and suspending need a reason.
 */
class ApproveServiceProviderRequest extends FormRequest
{
    /**
     * Authorization runs before validation, so a provider the user may not see is
     * reported as not found even when the payload is invalid.
     */
    public function authorize(): Response
    {
        return Gate::inspect('approve', $this->route('serviceProvider'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::enum(ProviderApprovalStatus::class)->except([ProviderApprovalStatus::Pending])],
            'reason' => [
                Rule::requiredIf(fn (): bool => ProviderApprovalStatus::tryFrom($this->string('decision')->toString())?->requiresReason() ?? false),
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    public function decision(): ProviderApprovalStatus
    {
        return ProviderApprovalStatus::from($this->string('decision')->toString());
    }

    public function reason(): ?string
    {
        return $this->filled('reason') ? $this->string('reason')->toString() : null;
    }
}
