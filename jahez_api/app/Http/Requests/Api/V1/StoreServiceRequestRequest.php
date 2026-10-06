<?php

namespace App\Http\Requests\Api\V1;

use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\ServiceProvider;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Readiness\ServiceEligibility;
use App\TransformationPlans\PlanItemRequests;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A factory member asks for one catalog service and sends the request to one or more
 * providers (ADR-015). The factory comes from the signed-in account, never from the
 * payload. The service must be available to the factory's readiness level, and every
 * provider must be eligible: approved, offering the service through an approved listing
 * and targeting one of the factory's sectors (ServiceEligibility, ADR-025).
 *
 * `transformation_plan_item_id` links the request to an item of the factory's published
 * transformation plan (ADR-025), for the same service; a provider IMC assigned to the
 * item is the only one the request may go to (PlanItemRequests).
 */
class StoreServiceRequestRequest extends FormRequest
{
    /**
     * Technical upper bound on providers per request (PROPOSED, OQ-38); not a business rule.
     */
    public const MAX_PROVIDERS = 20;

    public const INELIGIBLE_PROVIDERS = 'Each provider must be approved, offer this service and target one of your factory\'s sectors.';

    public const SERVICE_NOT_AVAILABLE = 'This service is not available to your factory\'s digital readiness level.';

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
            'transformation_plan_item_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['service', 'provider_ids', 'provider_ids.*', 'transformation_plan_item_id'])) {
                    return;
                }

                if (! app(ServiceEligibility::class)->isServiceAvailable($this->requestingFactory(), $this->service())) {
                    $validator->errors()->add('service', self::SERVICE_NOT_AVAILABLE);

                    return;
                }

                if ($this->planItemId() !== null) {
                    foreach (app(PlanItemRequests::class)->problems($this->requestingFactory(), $this->service(), $this->planItemId(), $this->providerIds()) as $field => $message) {
                        $validator->errors()->add($field, $message);
                    }

                    if ($validator->errors()->isNotEmpty()) {
                        return;
                    }
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
        return app(ServiceEligibility::class)
            ->providersFor($this->requestingFactory(), $this->service())
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
     * The transformation plan item the request is for, if any.
     */
    public function planItemId(): ?int
    {
        return $this->filled('transformation_plan_item_id') ? $this->integer('transformation_plan_item_id') : null;
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
