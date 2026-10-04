<?php

namespace App\Http\Requests\Api\V1;

use App\Billing\PolicyCalendar;
use App\Billing\PolicyContext;
use App\Enums\FinancialPolicyKind;
use App\Models\FinancialPolicy;
use App\Models\Sector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Which policy applies to a service (id), sectors (codes) and service provider (id) on a day and, for the
 * preview, what an amount comes to under the policies that apply (ADR-023). For IMC
 * administrators only; the result is never used to make a record.
 */
class ResolveFinancialPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', FinancialPolicy::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $preview = $this->routeIs('api.v1.financial-policies.preview');

        return [
            'kind' => [$preview ? 'prohibited' : 'required', 'string', Rule::enum(FinancialPolicyKind::class)],
            'catalog_service' => ['sometimes', 'nullable', 'integer', 'exists:catalog_services,id'],
            'sectors' => ['sometimes', 'array', 'max:20'],
            'sectors.*' => ['string', 'distinct', 'exists:sectors,code'],
            'service_provider' => ['sometimes', 'nullable', 'integer', 'exists:service_providers,id'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'amount' => [$preview ? 'required' : 'prohibited', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ];
    }

    public function context(): PolicyContext
    {
        return new PolicyContext(
            $this->filled('catalog_service') ? $this->integer('catalog_service') : null,
            array_values(Sector::query()->whereIn('code', (array) $this->input('sectors', []))->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()),
            $this->filled('service_provider') ? $this->integer('service_provider') : null,
        );
    }

    public function day(): CarbonImmutable
    {
        return $this->filled('date') ? PolicyCalendar::parse($this->string('date')->toString()) : PolicyCalendar::today();
    }
}
