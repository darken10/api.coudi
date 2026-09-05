<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Diagnostic;

use App\Models\DiagnosticCommandAck;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property string $status
 * @property string|null $message
 */
final class AckDiagnosticCommandRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(DiagnosticCommandAck::STATUSES)],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
