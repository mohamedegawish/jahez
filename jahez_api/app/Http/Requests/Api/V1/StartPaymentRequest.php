<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The factory starts paying an issued invoice. The `Idempotency-Key` header is required:
 * repeating the request with the same key returns the same payment instead of starting
 * a second charge.
 */
class StartPaymentRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('pay', $this->route('invoice'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,100}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'Send an Idempotency-Key header (8 to 100 letters, digits, - or _).',
            'idempotency_key.regex' => 'The Idempotency-Key header must be 8 to 100 letters, digits, - or _.',
        ];
    }

    public function idempotencyKey(): string
    {
        return $this->string('idempotency_key')->toString();
    }
}
