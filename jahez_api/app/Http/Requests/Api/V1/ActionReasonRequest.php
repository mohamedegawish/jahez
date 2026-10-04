<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The optional reason given with a marketplace action. Each action authorizes in its own
 * subclass, which runs before validation, so a record the user may not see is reported
 * as not found even when the reason is invalid.
 */
abstract class ActionReasonRequest extends FormRequest
{
    abstract public function authorize(): Response;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function reason(): ?string
    {
        return $this->filled('reason') ? $this->string('reason')->toString() : null;
    }
}
