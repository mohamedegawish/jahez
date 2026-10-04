<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Filters for the audit log. Every filter is allow-listed or type-checked; values are
 * bound as query parameters, never interpolated. Dates are interpreted in UTC.
 */
class ListAuditLogsRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 50;

    public const MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        return Gate::allows('viewAny', AuditLog::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'event' => ['sometimes', 'string', Rule::enum(AuditEvent::class)],
            'actor_user_id' => ['sometimes', 'integer', 'min:1'],
            'subject_type' => ['required_with:subject_id', 'string', Rule::in(array_values(AuditLog::SUBJECT_TYPES))],
            'subject_id' => ['required_with:subject_type', 'integer', 'min:1'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'cursor' => [
                'sometimes',
                'string',
                'max:500',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! self::isIssuedCursor($value)) {
                        $fail('The :attribute is invalid.');
                    }
                },
            ],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /**
     * Whether the value decodes like a cursor this endpoint issues: the id of the last
     * entry shown and a direction. The paginator fails with a server error on anything
     * else that decodes, and silently restarts from the first page on what does not.
     */
    private static function isIssuedCursor(string $value): bool
    {
        try {
            $cursor = Cursor::fromEncoded($value)?->toArray();
        } catch (Throwable) {
            return false;
        }

        return is_int($cursor['id'] ?? null) && is_bool($cursor['_pointsToNextItems'] ?? null);
    }

    public function perPage(): int
    {
        return $this->integer('per_page', self::DEFAULT_PER_PAGE);
    }

    /**
     * Start of the `from` day in UTC, if given.
     */
    public function fromTime(): ?Carbon
    {
        return $this->filled('from') ? Carbon::createFromFormat('Y-m-d', $this->string('from')->toString(), 'UTC')?->startOfDay() : null;
    }

    /**
     * End of the `to` day in UTC, if given (inclusive).
     */
    public function toTime(): ?Carbon
    {
        return $this->filled('to') ? Carbon::createFromFormat('Y-m-d', $this->string('to')->toString(), 'UTC')?->endOfDay() : null;
    }
}
