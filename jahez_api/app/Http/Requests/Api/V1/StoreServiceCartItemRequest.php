<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\BillingPeriod;
use App\Models\CatalogService;
use App\Models\Factory;
use App\Models\ServiceCartItem;
use App\Models\ServiceListingPackage;
use App\Models\User;
use App\Readiness\ServiceEligibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Put a provider's listing in the signed-in factory member's cart (ADR-027), with an
 * optional package, billing period and number of users. The provider must be eligible
 * for the factory and the service (ServiceEligibility); the package must be one of that
 * listing's, and the period one the package has a price for. Adding a listing already
 * in the cart replaces its choice.
 */
class StoreServiceCartItemRequest extends FormRequest
{
    public const PROVIDER_NOT_ELIGIBLE = 'This provider does not offer this service to your factory.';

    public const PACKAGE_OF_ANOTHER_LISTING = 'The package must be one of this provider\'s packages for this service.';

    public const PERIOD_NEEDS_PACKAGE = 'Choose a package before a billing period.';

    public const PERIOD_WITHOUT_PRICE = 'The chosen package has no price for this billing period.';

    public const CART_FULL = 'Your cart already holds the maximum number of items.';

    public function authorize(): bool
    {
        return Gate::allows('create', ServiceCartItem::class);
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
            'provider_id' => ['required', 'integer', 'min:1'],
            ...self::choiceRules(),
        ];
    }

    /**
     * The rules of the package, period and users choice, shared with the update.
     *
     * @return array<string, list<mixed>>
     */
    public static function choiceRules(): array
    {
        return [
            'package_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'billing_period' => ['sometimes', 'nullable', Rule::enum(BillingPeriod::class)],
            'users_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
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
                $service = $this->service();
                $eligibility = app(ServiceEligibility::class);

                if (! $eligibility->isServiceAvailable($factory, $service)) {
                    $validator->errors()->add('service', StoreServiceRequestRequest::SERVICE_NOT_AVAILABLE);

                    return;
                }

                if (! $eligibility->providersFor($factory, $service)->whereKey($this->providerId())->exists()) {
                    $validator->errors()->add('provider_id', self::PROVIDER_NOT_ELIGIBLE);

                    return;
                }

                foreach (self::choiceProblems($service->id, $this->providerId(), $this->packageId(), $this->billingPeriod()) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }

                $user = $this->user();
                $isNew = $user instanceof User && ! ServiceCartItem::query()
                    ->where('user_id', $user->id)
                    ->where('catalog_service_id', $service->id)
                    ->where('service_provider_id', $this->providerId())
                    ->exists();

                if ($isNew && ServiceCartItem::query()->where('user_id', $user->id)->count() >= ServiceCartItem::MAX_ITEMS) {
                    $validator->errors()->add('service', self::CART_FULL);
                }
            },
        ];
    }

    /**
     * What is wrong with a package and period choice for the listing, by field.
     *
     * @return array<string, string>
     */
    public static function choiceProblems(int $serviceId, int $providerId, ?int $packageId, ?BillingPeriod $period): array
    {
        if ($packageId === null) {
            return $period === null ? [] : ['billing_period' => self::PERIOD_NEEDS_PACKAGE];
        }

        $package = ServiceListingPackage::query()
            ->whereKey($packageId)
            ->where('catalog_service_id', $serviceId)
            ->where('service_provider_id', $providerId)
            ->first();

        if ($package === null) {
            return ['package_id' => self::PACKAGE_OF_ANOTHER_LISTING];
        }

        return $period !== null && $package->priceFor($period) === null ? ['billing_period' => self::PERIOD_WITHOUT_PRICE] : [];
    }

    public function service(): CatalogService
    {
        return CatalogService::query()->where('code', $this->string('service')->toString())->firstOrFail();
    }

    public function providerId(): int
    {
        return $this->integer('provider_id');
    }

    public function packageId(): ?int
    {
        return $this->filled('package_id') ? $this->integer('package_id') : null;
    }

    public function billingPeriod(): ?BillingPeriod
    {
        return $this->filled('billing_period') ? BillingPeriod::from($this->string('billing_period')->toString()) : null;
    }

    public function usersCount(): ?int
    {
        return $this->filled('users_count') ? $this->integer('users_count') : null;
    }
}
