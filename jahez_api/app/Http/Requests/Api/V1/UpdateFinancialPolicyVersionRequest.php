<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesPolicyVersionDraft;
use App\Models\FinancialPolicyVersion;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Change a draft version (ADR-023). Only the fields sent change; `parameters`, when
 * sent, replaces all of the version's values and must be complete.
 */
class UpdateFinancialPolicyVersionRequest extends FormRequest
{
    use ValidatesPolicyVersionDraft;

    public function authorize(): Response
    {
        return Gate::inspect('prepare', $this->version());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->draftRules($this->version()->policy()->firstOrFail()->kind, partial: true);
    }

    /**
     * @return array{parameters?: array<string, mixed>, effective_from?: string, effective_to?: string|null, change_reason?: string}
     */
    public function changes(): array
    {
        $changes = [];
        if ($this->has('parameters')) {
            $changes['parameters'] = (array) $this->input('parameters');
        }
        if ($this->has('effective_from')) {
            $changes['effective_from'] = $this->string('effective_from')->toString();
        }
        if ($this->has('effective_to')) {
            $changes['effective_to'] = $this->filled('effective_to') ? $this->string('effective_to')->toString() : null;
        }
        if ($this->has('change_reason')) {
            $changes['change_reason'] = $this->string('change_reason')->toString();
        }

        return $changes;
    }

    public function version(): FinancialPolicyVersion
    {
        /** @var FinancialPolicyVersion $version */
        $version = $this->route('financialPolicyVersion');

        return $version;
    }
}
