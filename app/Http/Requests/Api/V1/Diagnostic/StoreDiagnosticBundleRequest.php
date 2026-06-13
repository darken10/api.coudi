<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Diagnostic;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string|null                   $version
 * @property string|null                   $sent_at
 * @property string|null                   $db_name
 * @property string|null                   $platform
 * @property \Illuminate\Http\UploadedFile $bundle
 */
final class StoreDiagnosticBundleRequest extends FormRequest
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
            'bundle'   => ['required', 'file', 'mimes:zip', 'max:102400'], // max 100 Mo
            'version'  => ['nullable', 'string', 'max:20'],
            'sent_at'  => ['nullable', 'date'],
            'db_name'  => ['nullable', 'string', 'max:100'],
            'platform' => ['nullable', 'string', 'in:ios,android'],
        ];
    }
}
