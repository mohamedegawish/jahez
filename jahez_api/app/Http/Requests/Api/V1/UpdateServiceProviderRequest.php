<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Permission;
use App\Http\Requests\Api\V1\Concerns\ValidatesProviderProfile;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class UpdateServiceProviderRequest extends FormRequest
{
    use ValidatesProviderProfile;

    public const LEGAL_FIELD_NEEDS_REVIEW = 'IMC has verified this information. Submit a change request to change it.';

    /**
     * Authorization runs before validation, so a provider the user may not see is
     * reported as not found even when the payload is invalid.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('serviceProvider'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            ...$this->providerProfileRules(),
            ...$this->legalDetailRules(),
        ];
    }

    /**
     * Once IMC has approved a provider, its members may not change the verified legal
     * fields directly (ADR-019): a different value is refused and must go through a
     * change request. Sending the stored value again is accepted, so a client may send
     * the whole form. IMC administrators edit the fields directly.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $provider = $this->route('serviceProvider');
                $user = $this->user();

                if (! $provider instanceof ServiceProvider || ! $user instanceof User
                    || $user->hasPermission(Permission::ServiceProvidersUpdate)
                    || ! $provider->hasVerifiedLegalInformation()) {
                    return;
                }

                foreach (ServiceProvider::LEGAL_FIELDS as $field) {
                    if ($this->has($field) && ! $validator->errors()->has($field) && $this->input($field) !== $provider->getAttribute($field)) {
                        $validator->errors()->add($field, self::LEGAL_FIELD_NEEDS_REVIEW);
                    }
                }
            },
        ];
    }
}
