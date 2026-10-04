<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DocumentType;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Upload or replace one organization document (ADR-019), as multipart form data with
 * `type` and `file`. Whoever may edit the organization's profile may upload; the
 * controller decides whether a provider's legal document needs a change request.
 */
class StoreOrganizationDocumentRequest extends FormRequest
{
    /**
     * Authorization runs before validation, so an organization the user may not see is
     * reported as not found even when the payload is invalid.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('factory') ?? $this->route('serviceProvider'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $type = DocumentType::tryFrom((string) $this->input('type'));

        return [
            'type' => ['required', 'string', Rule::enum(DocumentType::class)],
            'file' => ['required', ...($type?->fileRules() ?? ['file'])],
        ];
    }

    public function documentType(): DocumentType
    {
        return DocumentType::from($this->string('type')->toString());
    }
}
