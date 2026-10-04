<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesOrganizationDetails;
use App\Models\ServiceProvider;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * A provider member asks IMC to change verified legal information (ADR-019): any of the
 * legal name, the registration numbers and the registration documents, as multipart
 * form data. Only values that differ from the stored ones are kept; a request that
 * changes nothing is refused.
 */
class StoreProviderChangeRequestRequest extends FormRequest
{
    use ValidatesOrganizationDetails;

    public const NOTHING_TO_CHANGE = 'Send at least one legal field with a new value or a new registration document.';

    /**
     * The document fields a change request may carry: never the logo, which members
     * replace directly.
     */
    public const CHANGE_DOCUMENT_FIELDS = ['commercial_registration_document', 'tax_registration_document'];

    /**
     * Authorization runs before validation, so a provider the user may not see is
     * reported as not found even when the payload is invalid.
     */
    public function authorize(): Response
    {
        return Gate::inspect('requestLegalChange', $this->route('serviceProvider'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $documentRules = $this->documentRules();

        return [
            ...$this->legalDetailRules(),
            ...array_intersect_key($documentRules, array_flip(self::CHANGE_DOCUMENT_FIELDS)),
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
                    $validator->errors()->add('changes', self::NOTHING_TO_CHANGE);
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
        /** @var ServiceProvider $provider */
        $provider = $this->route('serviceProvider');
        $changes = [];
        foreach (ServiceProvider::LEGAL_FIELDS as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->filled($field) ? $this->string($field)->trim()->toString() : null;
            if ($value !== $provider->getAttribute($field)) {
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
        foreach (self::CHANGE_DOCUMENT_FIELDS as $field) {
            $file = $this->file($field);
            if ($file instanceof UploadedFile) {
                $files[$field] = $file;
            }
        }

        return $files;
    }
}
