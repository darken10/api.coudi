<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string $password
 * @property string $code
 */
final class DisableTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // Mot de passe ET code : désactiver le second facteur est
            // exactement ce que tenterait quelqu'un ayant volé la session.
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}
