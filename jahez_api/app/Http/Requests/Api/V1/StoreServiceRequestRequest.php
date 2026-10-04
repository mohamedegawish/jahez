<?php

namespace App\Http\Requests\Api\V1;

use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\ServiceProvider;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A factory member asks for one catalog service and sends the request to one or more
 * providers (ADR-015). The factory comes from the signed-in account, never from the
 * payload, and every provider must be eligible: approved, offering the service and
 * targeting one of the factory's sectors (owner decision 2026-10-03).
 */
class StoreServiceRequestRequest extends FormRequest
{
    /**
     * Technical upper bound on providers per request (PROPOSED, OQ-38); not a business rule.
     */
    public const MAX_PROVIDERS = 20;

    public const INELIGIBLE_PROVIDERS = 'Each provider must be approved, offer this service and target one of your factory\'s sectors.';

    public function authorize(): bool
    {
        return Gate::allows('create', ServiceRequest::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'service' => ['required', 'string', Rule::exists('catalog_services', 'code')],
            'title' => ['required', 'string', 'max:200'],
            'need' => ['required', 'string', 'max:5000'],
            'requirements' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'provider_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_PROVIDERS],
            'provider_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['service', 'provider_ids', 'provider_ids.*'])) {
                    return;
                }

                if ($this->eligibleProviders()->count() !== count($this->providerIds())) {
                    $validator->errors()->add('provider_ids', self::INELIGIBLE_PROVIDERS);
                }
            },
        ];
    }

    public function service(): CatalogService
    {
        return CatalogService::query()->where('code', $this->string('service')->toString())->firstOrFail();
    }

    /**
     * The chosen providers that are eligible for the signed-in member's factory. The
     * controller runs it again with a lock when it saves the request.
     *
     * @return Builder<ServiceProvider>
     */
    public function eligibleProviders(): Builder
    {
        return ServiceProvider::query()
            ->eligibleFor($this->requestingFactory())
            ->offering($this->service())
            ->whereKey($this->providerIds());
    }

    /**
     * @return list<int>
     */
    public function providerIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('provider_ids', [])));
    }

    /**
     * The signed-in member's factory. authorize() already admits only factory members.
     */
    private function requestingFactory(): Factory
    {
        $factory = $this->user() instanceof User ? $this->user()->industrialFactory : null;

        if ($factory === null) {
            abort(403);
        }

        return $factory;
    }
}
