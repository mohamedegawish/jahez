<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ServiceProvider;
use App\Models\ServiceRequest;
use App\Readiness\ServiceEligibility;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * The requesting factory sends its open request to more providers (PROPOSED, OQ-38),
 * for example after every provider declined. Each provider must be eligible for the
 * request's factory and service, as when the request was created: the service must still
 * be available to the factory's readiness level (ServiceEligibility, ADR-025).
 * Authorization runs before validation.
 */
class AddServiceRequestProvidersRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('addProviders', $this->route('serviceRequest'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'provider_ids' => ['required', 'array', 'min:1', 'max:'.StoreServiceRequestRequest::MAX_PROVIDERS],
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
                if ($validator->errors()->hasAny(['provider_ids', 'provider_ids.*'])) {
                    return;
                }

                if ($this->eligibleProviders()->count() !== count($this->providerIds())) {
                    $validator->errors()->add('provider_ids', StoreServiceRequestRequest::INELIGIBLE_PROVIDERS);
                }
            },
        ];
    }

    /**
     * The chosen providers that are eligible for the request's factory and service. The
     * controller runs it again with a lock when it saves.
     *
     * @return Builder<ServiceProvider>
     */
    public function eligibleProviders(): Builder
    {
        $serviceRequest = $this->serviceRequest();

        return app(ServiceEligibility::class)
            ->providersFor($serviceRequest->industrialFactory()->firstOrFail(), $serviceRequest->service()->firstOrFail())
            ->whereKey($this->providerIds());
    }

    /**
     * @return list<int>
     */
    public function providerIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('provider_ids', [])));
    }

    private function serviceRequest(): ServiceRequest
    {
        $serviceRequest = $this->route('serviceRequest');

        if (! $serviceRequest instanceof ServiceRequest) {
            abort(404);
        }

        return $serviceRequest;
    }
}
