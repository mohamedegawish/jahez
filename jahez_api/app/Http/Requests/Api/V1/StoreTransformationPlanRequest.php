<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Factory;
use App\Models\TransformationPlan;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * IMC creates a factory's transformation plan with an empty first draft (ADR-025). The
 * factory comes from the route; its readiness basis is read by the server.
 */
class StoreTransformationPlanRequest extends FormRequest
{
    public function authorize(): Response
    {
        $factory = $this->route('factory');

        return $factory instanceof Factory
            ? Gate::inspect('create', [TransformationPlan::class, $factory])
            : Response::denyAsNotFound();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'summary_ar' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
