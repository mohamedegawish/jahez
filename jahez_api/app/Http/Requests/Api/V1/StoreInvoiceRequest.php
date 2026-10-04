<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Invoice;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Draft an invoice for an agreement. It takes no input: the first line is the agreed
 * service at the agreed price, and the issuer adds or removes lines on the draft.
 */
class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', [Invoice::class, $this->route('agreement')]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
