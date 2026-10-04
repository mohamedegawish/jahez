<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A line on a draft invoice: a description, a whole-number quantity and a unit amount
 * (a decimal string, at most two decimals). The issuer decides its lines; the platform
 * adds no fee, tax or share line of its own.
 */
class StoreInvoiceLineRequest extends FormRequest
{
    /**
     * Technical bound on the quantity, not a business rule.
     */
    public const MAX_QUANTITY = 1_000_000;

    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->route('invoice'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:500'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
            'unit_amount' => ['required', 'decimal:0,2', 'min:0', 'max:'.StoreOfferRequest::MAX_PRICE],
        ];
    }
}
