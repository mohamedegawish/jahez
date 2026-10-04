<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * An IMC administrator renames, deactivates or reactivates an account. Role and
 * organization cannot be changed here; fields that are not listed are ignored.
 */
class UpdateUserRequest extends FormRequest
{
    /**
     * Authorization runs before validation, so an account the user may not see is
     * reported as not found even when the payload is invalid.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('user'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Administrators may not deactivate their own account, so the platform can
     * never lock itself out by accident.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $targetUser = $this->route('user');

                if ($this->has('is_active') && ! $this->boolean('is_active')
                    && $targetUser instanceof User && $targetUser->is($this->user())) {
                    $validator->errors()->add('is_active', 'You cannot deactivate your own account.');
                }
            },
        ];
    }
}
