<?php

namespace App\Http\Requests\Api\V1;

use App\Models\FinancialPolicyVersion;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * An action that changes a version's status (ADR-023): approve (optional note), reject
 * and archive (reason required), end (reason and last day required). The action is the
 * last part of the route name; authorization runs before validation.
 */
class DecideFinancialPolicyVersionRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect(match ($this->action()) {
            'approve', 'reject' => 'decide',
            'archive' => 'archive',
            default => 'end',
        }, $this->version());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $action = $this->action();

        return [
            'reason' => [$action === 'approve' ? 'nullable' : 'required', 'string', 'min:3', 'max:2000'],
            ...($action === 'end' ? ['effective_to' => ['required', 'date_format:Y-m-d']] : []),
        ];
    }

    public function reason(): ?string
    {
        return $this->filled('reason') ? $this->string('reason')->toString() : null;
    }

    public function version(): FinancialPolicyVersion
    {
        /** @var FinancialPolicyVersion $version */
        $version = $this->route('financialPolicyVersion');

        return $version;
    }

    private function action(): string
    {
        $route = $this->route();
        $name = is_object($route) ? (string) $route->getName() : '';

        return substr($name, (int) strrpos($name, '.') + 1);
    }
}
