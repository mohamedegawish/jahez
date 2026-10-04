<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Listing invoices: a party's own, or all of them for IMC billing oversight.
 * `filter[from]`/`filter[to]` are UTC days of the invoice's creation; `filter[counterparty]`
 * is the other party's id (the provider for a factory, the factory for a provider).
 */
class ListInvoicesRequest extends ListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Invoice::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'filter' => ['sometimes', 'array:status,agreement,service,from,to,counterparty'],
            'filter.status' => ['sometimes', 'string', Rule::enum(InvoiceStatus::class)],
            'filter.agreement' => ['sometimes', 'integer', 'min:1'],
            'filter.service' => ['sometimes', 'string', 'max:100'],
            'filter.from' => ['sometimes', 'date_format:Y-m-d'],
            'filter.to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:filter.from'],
            'filter.counterparty' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
