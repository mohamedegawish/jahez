<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * The report period and filters (ADR-020). `from` and `to` are UTC days, at most two
 * years apart; the default is the last twelve months up to today.
 */
class MarketplaceReportRequest extends FormRequest
{
    public const MAX_DAYS = 731;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && (
            $user->factory_id !== null
            || $user->service_provider_id !== null
            || $user->hasPermission(Permission::ServiceRequestsViewAny)
        );
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'filter' => ['sometimes', 'array:service'],
            'filter.service' => ['sometimes', 'string', 'exists:catalog_services,code'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->isEmpty() && $this->from()->diffInDays($this->to()) > self::MAX_DAYS) {
                    $validator->errors()->add('from', 'The period may be at most two years.');
                }
            },
        ];
    }

    public function from(): Carbon
    {
        return $this->filled('from')
            ? Carbon::parse($this->string('from')->toString(), 'UTC')->startOfDay()
            : now('UTC')->subMonths(12)->startOfDay();
    }

    public function to(): Carbon
    {
        return $this->filled('to')
            ? Carbon::parse($this->string('to')->toString(), 'UTC')->endOfDay()
            : now('UTC')->endOfDay();
    }
}
