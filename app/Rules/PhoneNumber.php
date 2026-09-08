<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\PhoneNumberNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || PhoneNumberNormalizer::toE164($value) === null) {
            $fail('The :attribute must be a valid US phone number.');
        }
    }
}
