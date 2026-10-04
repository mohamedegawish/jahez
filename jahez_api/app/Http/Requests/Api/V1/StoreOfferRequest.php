<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Offer;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * A provider's offer version (ADR-015). `based_on_version` is the latest version the
 * provider saw (null for the first offer); the controller refuses the offer with 409
 * when a newer version exists, which also stops a retried request from creating a
 * duplicate version. The price is a decimal amount in EGP only (owner decision
 * 2026-10-03), informational, and creates no invoice or payment.
 */
class StoreOfferRequest extends FormRequest
{
    /**
     * Largest amount DECIMAL(14,2) can hold.
     */
    public const MAX_PRICE = '999999999999.99';

    /**
     * Technical bound on the duration (ten years), not a business rule.
     */
    public const MAX_DURATION_DAYS = 3650;

    /**
     * Authorization runs before validation, so a thread the user may not see is
     * reported as not found even when the payload is invalid.
     */
    public function authorize(): Response
    {
        return Gate::inspect('submitOffer', $this->route('providerRequest'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'based_on_version' => ['present', 'nullable', 'integer', 'min:1'],
            'scope' => ['required', 'string', 'max:5000'],
            'deliverables' => ['required', 'string', 'max:5000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:'.self::MAX_DURATION_DAYS],
            'valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'price' => ['required', 'array:amount,currency'],
            'price.amount' => ['required', 'decimal:0,2', 'min:0', 'max:'.self::MAX_PRICE],
            'price.currency' => ['required', 'string', Rule::in([Offer::CURRENCY])],
        ];
    }

    public function basedOnVersion(): ?int
    {
        return $this->filled('based_on_version') ? $this->integer('based_on_version') : null;
    }
}
