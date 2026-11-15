<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Employee;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string $phone
 * @property string $code
 * @property string|null $device_id
 */
final class VerifyActivationCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:40'],
            'code' => ['required', 'string', 'max:12'],
            'device_id' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.required' => 'Saisissez votre numéro de téléphone.',
            'code.required' => 'Saisissez le code à 6 chiffres reçu.',
        ];
    }
}
