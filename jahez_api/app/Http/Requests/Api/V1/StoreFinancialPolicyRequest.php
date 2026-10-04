<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\FinancialPolicyKind;
use App\Enums\FinancialPolicyScope;
use App\Http\Requests\Api\V1\Concerns\ValidatesPolicyVersionDraft;
use App\Models\FinancialPolicy;
use App\Models\Sector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Create a financial policy for a kind and scope, with its first draft version
 * (ADR-023). Nothing here takes effect: the draft must be submitted and approved by
 * another administrator.
 */
class StoreFinancialPolicyRequest extends FormRequest
{
    use ValidatesPolicyVersionDraft;

    public function authorize(): bool
    {
        return Gate::allows('create', FinancialPolicy::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $scope = $this->input('scope_type');
        $byId = in_array($scope, [FinancialPolicyScope::CatalogService->value, FinancialPolicyScope::ServiceProvider->value], true);

        return [
            'kind' => ['required', 'string', Rule::enum(FinancialPolicyKind::class)],
            'scope_type' => ['required', 'string', Rule::enum(FinancialPolicyScope::class)],
            // Services and providers are named by id, sectors by their public code (as everywhere in the API).
            'scope_id' => [$byId ? 'required' : 'prohibited', 'nullable', 'integer', 'min:1'],
            'scope_code' => [$scope === FinancialPolicyScope::Sector->value ? 'required' : 'prohibited', 'nullable', 'string', 'exists:sectors,code'],
            'name_ar' => ['required', 'string', 'max:191'],
            'description_ar' => ['nullable', 'string', 'max:2000'],
            ...$this->draftRules($this->kind()),
        ];
    }

    /**
     * The scope record id: the given id, or the id of the sector with the given code.
     */
    public function scopeId(): ?int
    {
        if ($this->filled('scope_code')) {
            return (int) Sector::query()->where('code', $this->string('scope_code')->toString())->value('id');
        }

        return $this->filled('scope_id') ? $this->integer('scope_id') : null;
    }

    public function kind(): ?FinancialPolicyKind
    {
        return FinancialPolicyKind::tryFrom((string) $this->input('kind'));
    }
}
