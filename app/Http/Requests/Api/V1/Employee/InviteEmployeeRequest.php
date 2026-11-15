<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Employee;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Invitation ou renvoi d'un code. Sans numéro ni canal, le renvoi reprend
 * ceux de la dernière invitation ; le service vérifie qu'un numéro valide
 * existe au final.
 *
 * @property string|null $phone
 * @property string|null $channel
 */
final class InviteEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:40'],
            'channel' => ['nullable', 'string', 'in:sms,whatsapp'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['channel.in' => 'Choisissez l’envoi par SMS ou par WhatsApp.'];
    }
}
