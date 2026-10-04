<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * An IMC administrator creates an account (ADR-011). The administrator chooses the
 * role and organization; nobody can set these for their own account.
 */
class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('create', User::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $role = $this->input('role');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', 'string', Rule::enum(Role::class)],
            'factory_id' => [
                Rule::requiredIf($role === Role::FactoryMember->value),
                Rule::prohibitedIf($role !== Role::FactoryMember->value),
                'integer',
                Rule::exists('factories', 'id'),
            ],
            'service_provider_id' => [
                Rule::requiredIf($role === Role::ProviderMember->value),
                Rule::prohibitedIf($role !== Role::ProviderMember->value),
                'integer',
                Rule::exists('service_providers', 'id'),
            ],
        ];
    }

    public function role(): Role
    {
        return Role::from($this->string('role')->toString());
    }

    public function factoryId(): ?int
    {
        return $this->filled('factory_id') ? $this->integer('factory_id') : null;
    }

    public function serviceProviderId(): ?int
    {
        return $this->filled('service_provider_id') ? $this->integer('service_provider_id') : null;
    }
}
