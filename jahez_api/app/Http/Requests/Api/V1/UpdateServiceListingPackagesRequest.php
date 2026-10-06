<?php

namespace App\Http\Requests\Api\V1;

use App\Billing\Money;
use App\Models\ServiceListingPackage;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * The provider's packages for one of its listings, in display order (ADR-027): each a
 * name and, each optional, a monthly price, an annual price (EGP, informational) and the
 * number of users the price covers, with at least one price. An empty list removes every
 * package. The whole list replaces the listing's packages.
 */
class UpdateServiceListingPackagesRequest extends FormRequest
{
    public const PRICE_REQUIRED = 'Give a monthly price, an annual price or both.';

    public function authorize(): Response
    {
        return Gate::inspect('updateListingPackages', $this->route('serviceProvider'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'packages' => ['present', 'array', 'max:'.ServiceListingPackage::MAX_PER_LISTING],
            'packages.*' => ['array:name_ar,monthly_price,annual_price,users_count'],
            'packages.*.name_ar' => ['required', 'string', 'max:120', 'distinct'],
            'packages.*.monthly_price' => ['nullable', 'decimal:0,2', 'min:0', 'max:'.StoreOfferRequest::MAX_PRICE],
            'packages.*.annual_price' => ['nullable', 'decimal:0,2', 'min:0', 'max:'.StoreOfferRequest::MAX_PRICE],
            'packages.*.users_count' => ['nullable', 'integer', 'min:1', 'max:1000000'],
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

                foreach ($this->packages() as $index => $package) {
                    if ($package['monthly_price'] === null && $package['annual_price'] === null) {
                        $validator->errors()->add("packages.{$index}.monthly_price", self::PRICE_REQUIRED);
                    }
                }
            },
        ];
    }

    /**
     * The packages in display order, prices as two-decimal strings.
     *
     * @return list<array{name_ar: string, monthly_price: string|null, annual_price: string|null, users_count: int|null}>
     */
    public function packages(): array
    {
        $price = fn (mixed $value): ?string => $value === null || $value === '' ? null : Money::fromMinor(Money::toMinor((string) $value));

        return array_values(array_map(fn (mixed $package): array => [
            'name_ar' => trim((string) (is_array($package) ? ($package['name_ar'] ?? '') : '')),
            'monthly_price' => is_array($package) ? $price($package['monthly_price'] ?? null) : null,
            'annual_price' => is_array($package) ? $price($package['annual_price'] ?? null) : null,
            'users_count' => is_array($package) && isset($package['users_count']) ? (int) $package['users_count'] : null,
        ], (array) $this->input('packages', [])));
    }
}
