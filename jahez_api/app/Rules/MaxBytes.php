<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Limits a string by its UTF-8 byte length. Used for passwords because bcrypt
 * silently ignores everything after 72 bytes (about 36 Arabic characters).
 */
class MaxBytes implements ValidationRule
{
    public function __construct(private readonly int $maxBytes) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > $this->maxBytes) {
            $fail('The :attribute is too long.');
        }
    }
}
