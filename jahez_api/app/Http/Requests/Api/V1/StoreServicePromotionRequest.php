<?php

namespace App\Http\Requests\Api\V1;

use App\Models\CatalogService;
use App\Models\ServicePromotion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An IMC administrator promotes a provider's listing of a catalog service (ADR-020). The
 * provider must offer the service; promoting a listing never makes it visible to a
 * factory that is not eligible for it.
 */
class StoreServicePromotionRequest extends FormRequest
{
    public const MAX_PRIORITY = 1000;

    public function authorize(): bool
    {
        return Gate::allows('create', ServicePromotion::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'service_provider_id' => ['required', 'integer', Rule::exists('service_providers', 'id')],
            'service' => ['required', 'string', Rule::exists('catalog_services', 'code')],
            'headline' => ['sometimes', 'nullable', 'string', 'max:120'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_PRIORITY],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at', 'after:now'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['service_provider_id', 'service'])) {
                    return;
                }

                $offers = DB::table('catalog_service_service_provider')
                    ->where('service_provider_id', $this->integer('service_provider_id'))
                    ->where('catalog_service_id', $this->service()->id)
                    ->exists();

                if (! $offers) {
                    $validator->errors()->add('service', 'The provider does not offer this service.');
                }
            },
        ];
    }

    public function service(): CatalogService
    {
        return CatalogService::query()->where('code', $this->string('service')->toString())->firstOrFail();
    }
}
