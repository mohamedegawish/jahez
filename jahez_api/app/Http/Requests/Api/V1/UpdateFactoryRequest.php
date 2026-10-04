<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Permission;
use App\Http\Requests\Api\V1\Concerns\ValidatesCodeLists;
use App\Http\Requests\Api\V1\Concerns\ValidatesFactoryProfile;
use App\Models\Factory;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateFactoryRequest extends FormRequest
{
    use ValidatesCodeLists;
    use ValidatesFactoryProfile;

    public const LEGAL_FIELD_NEEDS_REVIEW = 'This legal information is recorded. Submit a change request for IMC to review.';

    /**
     * Authorization runs before validation, so a factory the user may not see is
     * reported as not found even when the payload is invalid.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('factory'));
    }

    /**
     * Get the validation rules that apply to the request. Members edit the name, the
     * sectors (owner decision 2026-10-03) and the registration details (ADR-019); the
     * size is for IMC administrators, so a size sent by a member is ignored.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            ...$this->codeListRules('sectors', 'sectors'),
            ...$this->factoryProfileRules(),
            ...($this->user() instanceof User && $this->user()->hasPermission(Permission::FactoriesUpdate) ? [
                'size' => ['sometimes', 'nullable', 'string', Rule::in(array_keys((array) config('jahez.factories.sizes')))],
            ] : []),
        ];
    }

    /**
     * A member may fill an empty legal field, but a recorded value changes only through a
     * change request IMC reviews (ADR-020). Sending the stored value again is accepted,
     * so a client may send the whole form. IMC administrators edit directly.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $factory = $this->route('factory');
                $user = $this->user();

                if (! $factory instanceof Factory || ! $user instanceof User || $user->hasPermission(Permission::FactoriesUpdate)) {
                    return;
                }

                foreach (Factory::LEGAL_FIELDS as $field) {
                    $sent = $this->filled($field) ? trim($this->string($field)->toString()) : null;
                    if ($this->has($field) && ! $validator->errors()->has($field) && $factory->legalValueIsRecorded($field) && $sent !== $factory->getAttribute($field)) {
                        $validator->errors()->add($field, self::LEGAL_FIELD_NEEDS_REVIEW);
                    }
                }
            },
        ];
    }
}
