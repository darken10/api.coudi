<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Diagnostic;

use App\Models\DiagnosticCommand;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property string|null $company_id
 * @property string $action
 * @property string|null $file_name
 */
final class StoreDiagnosticCommandRequest extends FormRequest
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
            'company_id' => ['nullable', 'uuid', 'exists:companies,id'],
            'action' => ['required', 'string', Rule::in(DiagnosticCommand::ACTIONS)],
            // Devient un nom de fichier sur le téléphone : on interdit tout ce
            // qui pourrait sortir du dossier de logs.
            'file_name' => [
                'required_if:action,'.DiagnosticCommand::ACTION_NEW_LOG_FILE,
                'nullable',
                'string',
                'max:60',
                'regex:/^[A-Za-z0-9_-]+$/',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file_name.regex' => 'Le nom de fichier ne peut contenir que lettres, chiffres, tiret et souligné.',
        ];
    }
}
