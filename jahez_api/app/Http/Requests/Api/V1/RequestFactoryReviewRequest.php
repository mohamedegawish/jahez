<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A factory that was rejected or asked for corrections asks IMC to review it again
 * (ADR-021), optionally saying what it changed. Authorization runs before validation.
 */
class RequestFactoryReviewRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('requestReview', $this->route('factory'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function note(): ?string
    {
        return $this->filled('note') ? $this->string('note')->toString() : null;
    }
}
