<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\BillingPeriod;
use App\Models\ServiceCartItem;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Change the package, billing period or number of users of an item in one's own cart
 * (ADR-027). A field left out keeps its value; null clears it. The result is checked as
 * a whole against the listing's current packages.
 */
class UpdateServiceCartItemRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->item());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return StoreServiceCartItemRequest::choiceRules();
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

                $item = $this->item();

                foreach (StoreServiceCartItemRequest::choiceProblems($item->catalog_service_id, $item->service_provider_id, $this->packageId(), $this->billingPeriod()) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }

    public function item(): ServiceCartItem
    {
        /** @var ServiceCartItem $item */
        $item = $this->route('serviceCartItem');

        return $item;
    }

    public function packageId(): ?int
    {
        if (! $this->has('package_id')) {
            return $this->item()->service_listing_package_id;
        }

        return $this->filled('package_id') ? $this->integer('package_id') : null;
    }

    public function billingPeriod(): ?BillingPeriod
    {
        if (! $this->has('billing_period')) {
            return $this->item()->billing_period;
        }

        return $this->filled('billing_period') ? BillingPeriod::from($this->string('billing_period')->toString()) : null;
    }

    public function usersCount(): ?int
    {
        if (! $this->has('users_count')) {
            return $this->item()->users_count;
        }

        return $this->filled('users_count') ? $this->integer('users_count') : null;
    }
}
