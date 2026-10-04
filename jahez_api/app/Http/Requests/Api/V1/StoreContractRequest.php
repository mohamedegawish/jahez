<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Contract;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A contract draft for an agreement (ADR-017; OQ-17 interim: not binding). It must carry
 * the knowledge-transfer commitment DOC §6 asks for: at least two IMC engineers trained
 * in the field throughout the execution, with a detailed training plan. Authorization
 * runs before validation.
 */
class StoreContractRequest extends FormRequest
{
    /**
     * Technical upper bound on trainees (the column holds up to 255), not a business rule.
     */
    public const MAX_TRAINEES = 255;

    public function authorize(): Response
    {
        return Gate::inspect('draftContract', $this->route('agreement'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'knowledge_transfer' => ['required', 'array:trainees,training_plan'],
            'knowledge_transfer.trainees' => ['required', 'integer', 'min:'.Contract::MIN_KNOWLEDGE_TRANSFER_TRAINEES, 'max:'.self::MAX_TRAINEES],
            'knowledge_transfer.training_plan' => ['required', 'string', 'max:10000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'knowledge_transfer.trainees.min' => 'DOC §6 requires training at least '.Contract::MIN_KNOWLEDGE_TRANSFER_TRAINEES.' IMC engineers.',
        ];
    }
}
