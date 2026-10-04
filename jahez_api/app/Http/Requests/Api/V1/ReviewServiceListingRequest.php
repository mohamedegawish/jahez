<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ServiceListingStatus;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * An IMC decision on one service a provider lists (ADR-021): approve, reject or suspend.
 * Rejecting and suspending need a reason. Authorization runs before validation.
 */
class ReviewServiceListingRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('reviewListing', $this->route('serviceProvider'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::enum(ServiceListingStatus::class)->except([ServiceListingStatus::Pending])],
            'reason' => [
                Rule::requiredIf(fn (): bool => ServiceListingStatus::tryFrom($this->string('decision')->toString())?->requiresReason() ?? false),
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    public function decision(): ServiceListingStatus
    {
        return ServiceListingStatus::from($this->string('decision')->toString());
    }

    public function reason(): ?string
    {
        return $this->filled('reason') ? $this->string('reason')->toString() : null;
    }
}
