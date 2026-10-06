<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ReadinessLevelService;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * IMC makes a catalog service available to a readiness level, or switches it on or off
 * there (ADR-025). The level and the service come from the route; nothing else is read.
 */
class UpdateReadinessLevelServiceRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('manage', ReadinessLevelService::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function isActive(): bool
    {
        return $this->has('is_active') ? $this->boolean('is_active') : true;
    }
}
