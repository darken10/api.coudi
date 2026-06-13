<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Diagnostic;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string|null $version
 * @property string|null $sent_at
 * @property string|null $file
 * @property array|null  $memory
 * @property string|null $platform
 */
final class StoreDiagnosticLogRequest extends FormRequest
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
            'version'          => ['nullable', 'string', 'max:20'],
            'sent_at'          => ['nullable', 'date'],
            'platform'         => ['nullable', 'string', 'in:ios,android'],
            'file'             => ['nullable', 'string'],
            'memory'           => ['nullable', 'array'],
            'memory.*.ts'      => ['nullable', 'string'],
            'memory.*.level'   => ['nullable', 'integer', 'between:0,4'],
            'memory.*.label'   => ['nullable', 'string', 'max:100'],
            'memory.*.message' => ['required_with:memory', 'string'],
            'memory.*.meta'    => ['nullable'],
            'memory.*.error'   => ['nullable', 'array'],
        ];
    }
}
