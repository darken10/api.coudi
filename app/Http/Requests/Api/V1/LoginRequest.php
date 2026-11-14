<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Connexion par email ou téléphone.
 *
 * `login` est l'identifiant générique utilisé par le mobile ; `email` et
 * `phone` restent acceptés pour ne pas casser les clients existants.
 *
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $login
 * @property string $password
 */
final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['nullable', 'string', 'email', 'max:255', 'required_without_all:phone,login'],
            'phone' => ['nullable', 'string', 'max:40', 'required_without_all:email,login'],
            'login' => ['nullable', 'string', 'max:255', 'required_without_all:email,phone'],
            'password' => ['required', 'string'],
        ];
    }
}
