<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Diagnostic;

use App\Models\DiagnosticSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property string|null $company_id
 * @property string $log_rotation
 * @property int $log_retention
 */
final class UpdateDiagnosticSettingsRequest extends FormRequest
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
            // Omis : on met à jour le défaut global.
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'log_rotation' => ['required', 'string', Rule::in(DiagnosticSetting::ROTATIONS)],
            'log_retention' => ['required', 'integer', 'min:1', 'max:60'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'log_rotation.in' => 'La rotation doit valoir daily, weekly ou monthly.',
            'log_retention.min' => 'Il faut conserver au moins une période de logs.',
            'log_retention.max' => 'La rétention ne peut pas dépasser 60 périodes.',
        ];
    }
}
