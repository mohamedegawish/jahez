<?php

namespace App\Http\Requests\Api\V1;

use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\ServiceCartItem;
use App\Models\ServiceRequest;
use App\Readiness\ServiceEligibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Send services from the signed-in factory member's cart (ADR-027): for each service, a
 * title and a need (as for any request, ADR-015). Each becomes one service request sent
 * to every provider in the cart for that service, with the package, period and users
 * chosen for each. Services left out stay in the cart. Every provider must still be
 * eligible; the controller checks it again inside the transaction.
 */
class CheckoutServiceCartRequest extends FormRequest
{
    public const NOT_IN_CART = 'This service is not in your cart.';

    public const INELIGIBLE_ITEMS = 'A provider in your cart no longer offers this service to your factory: remove it before sending.';

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
            'requests' => ['required', 'array', 'min:1', 'max:'.ServiceCartItem::MAX_ITEMS],
            'requests.*' => ['array:service,title,need,requirements'],
            'requests.*.service' => ['required', 'string', 'distinct', Rule::exists('catalog_services', 'code')],
            'requests.*.title' => ['required', 'string', 'max:200'],
            'requests.*.need' => ['required', 'string', 'max:5000'],
            'requests.*.requirements' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $factory = Factory::query()->findOrFail((int) $this->user()?->factory_id);
                $eligibility = app(ServiceEligibility::class);
                $items = ServiceCartItem::query()->where('user_id', (int) $this->user()?->id)->get()->groupBy('catalog_service_id');

                foreach ($this->entries() as $entry) {
                    $field = "requests.{$entry['index']}.service";
                    $providerIds = $items->get($entry['service']->id, collect())->pluck('service_provider_id')->all();

                    if ($providerIds === []) {
                        $validator->errors()->add($field, self::NOT_IN_CART);
                    } elseif (! $eligibility->isServiceAvailable($factory, $entry['service'])) {
                        $validator->errors()->add($field, StoreServiceRequestRequest::SERVICE_NOT_AVAILABLE);
                    } elseif (count($providerIds) > StoreServiceRequestRequest::MAX_PROVIDERS) {
                        $validator->errors()->add($field, 'A request can be sent to at most '.StoreServiceRequestRequest::MAX_PROVIDERS.' providers.');
                    } elseif ($eligibility->providersFor($factory, $entry['service'])->whereKey($providerIds)->count() !== count($providerIds)) {
                        $validator->errors()->add($field, self::INELIGIBLE_ITEMS);
                    }
                }
            },
        ];
    }

    /**
     * The services to send, in catalog id order (so concurrent checkouts lock the same
     * rows in the same order), each with its position in the payload for error keys.
     *
     * @return list<array{index: int, service: CatalogService, title: string, need: string, requirements: string|null}>
     */
    public function entries(): array
    {
        $requests = array_values((array) $this->input('requests', []));
        $services = CatalogService::query()->whereIn('code', array_column($requests, 'service'))->get()->keyBy('code');
        $entries = [];

        foreach ($requests as $index => $entry) {
            $service = $services->get($entry['service'] ?? null);

            if ($service === null) {
                continue;
            }

            $entries[] = [
                'index' => $index,
                'service' => $service,
                'title' => (string) $entry['title'],
                'need' => (string) $entry['need'],
                'requirements' => isset($entry['requirements']) && $entry['requirements'] !== '' ? (string) $entry['requirements'] : null,
            ];
        }

        usort($entries, fn (array $a, array $b): int => $a['service']->id <=> $b['service']->id);

        return $entries;
    }
}
