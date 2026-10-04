<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A negotiation message from one of the two parties of a provider request (ADR-015).
 */
class StoreNegotiationMessageRequest extends FormRequest
{
    public const MAX_LENGTH = 5000;

    /**
     * Authorization runs before validation, so a thread the user may not see is
     * reported as not found even when the payload is invalid.
     */
    public function authorize(): Response
    {
        return Gate::inspect('negotiate', $this->route('providerRequest'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.self::MAX_LENGTH],
        ];
    }
}
