<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Numéro valide, avec ou sans indicatif (le pays par défaut s'applique). */
final class ValidPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! PhoneNumber::isValid($value)) {
            $fail('Ce numéro de téléphone n’est pas valide. Exemple : 70 12 34 56 ou +226 70 12 34 56.');
        }
    }
}
