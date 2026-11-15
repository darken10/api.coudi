<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Employee;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string $ticket
 * @property string $password
 */
final class CompleteActivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'ticket' => ['required', 'string', 'size:64'],
            // Même règle que l'inscription et la réinitialisation.
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
        ];
    }
}
