<?php

namespace App\Http\Requests\Api\V1;

use App\Billing\PolicyCalendar;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * An authorised administrator records money received outside the platform, such as a
 * bank transfer (ADR-023). The amount is never trusted to settle the invoice by itself:
 * the server checks it against what is outstanding and the payment terms. The evidence
 * reference (the bank's transfer reference) is unique, and the `Idempotency-Key` header
 * makes a retried request return the same entry.
 */
class RecordManualPaymentRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('recordPayment', $this->route('invoice'));
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
            'amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.PolicyCalendar::today()->toDateString()],
            'reference' => ['required', 'string', 'max:100'],
            'evidence_note' => ['nullable', 'string', 'max:2000'],
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
            'amount.regex' => 'The amount must be a positive decimal string with at most two decimals.',
            'amount.not_regex' => 'The amount must be greater than zero.',
        ];
    }

    public function idempotencyKey(): string
    {
        return $this->string('idempotency_key')->toString();
    }
}
