<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\PasswordResetOtp;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property string $identifier
 * @property string $channel
 */
final class RequestOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'string', Rule::in(PasswordResetOtp::CHANNELS)],
        ];
    }
}
