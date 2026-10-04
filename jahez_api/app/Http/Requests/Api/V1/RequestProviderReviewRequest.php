<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A rejected provider asks IMC to review it again (PROPOSED), optionally saying what it
 * changed. Authorization runs before validation.
 */
class RequestProviderReviewRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('requestReview', $this->route('serviceProvider'));
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
