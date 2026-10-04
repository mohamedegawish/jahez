<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesOrganizationDetails;
use App\Models\Factory;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * A factory member asks IMC to change recorded legal information (ADR-020), as multipart
 * form data: any of the legal name, the registration numbers and the registration
 * documents. Only values that differ from the stored ones are kept; a request that
 * changes nothing is refused.
 */
class StoreFactoryChangeRequestRequest extends FormRequest
{
    use ValidatesOrganizationDetails;

    /**
     * Authorization runs before validation, so a factory the user may not see is
     * reported as not found even when the payload is invalid.
     */
    public function authorize(): Response
    {
        return Gate::inspect('requestLegalChange', $this->route('factory'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->legalDetailRules(),
            ...array_intersect_key($this->documentRules(), array_flip(StoreProviderChangeRequestRequest::CHANGE_DOCUMENT_FIELDS)),
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->changedFields() === [] && $this->documentFiles() === []) {
                    $validator->errors()->add('changes', StoreProviderChangeRequestRequest::NOTHING_TO_CHANGE);
                }
            },
        ];
    }

    /**
     * The legal fields sent with a value different from the stored one.
     *
     * @return array<string, string|null>
     */
    public function changedFields(): array
    {
        /** @var Factory $factory */
        $factory = $this->route('factory');
        $changes = [];
        foreach (Factory::LEGAL_FIELDS as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->filled($field) ? $this->string($field)->trim()->toString() : null;
            if ($value !== $factory->getAttribute($field)) {
                $changes[$field] = $value;
            }
        }

        return $changes;
    }

    /**
     * The uploaded documents by request field.
     *
     * @return array<string, UploadedFile>
     */
    public function documentFiles(): array
    {
        $files = [];
        foreach (StoreProviderChangeRequestRequest::CHANGE_DOCUMENT_FIELDS as $field) {
            $file = $this->file($field);
            if ($file instanceof UploadedFile) {
                $files[$field] = $file;
            }
        }

        return $files;
    }
}
