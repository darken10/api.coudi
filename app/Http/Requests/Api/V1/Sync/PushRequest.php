<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Sync;

use App\Sync\SyncRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Un lot d'écritures venues de la file d'attente locale d'un appareil.
 *
 * Le lot est borné : au-delà, une coupure réseau en fin d'envoi ferait tout
 * recommencer, et sur une liaison lente c'est la boucle sans fin assurée.
 */
final class PushRequest extends FormRequest
{
    public const MAX_OPS = 500;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ops' => ['required', 'array', 'min:1', 'max:'.self::MAX_OPS],
            'ops.*.op_id' => ['required', 'uuid'],
            'ops.*.entity' => ['required', 'string', Rule::in(SyncRegistry::keys())],
            'ops.*.op' => ['required', 'string', Rule::in(['upsert', 'delete'])],
            'ops.*.id' => ['required', 'uuid'],
            'ops.*.updated_at' => ['nullable', 'date'],
            'ops.*.data' => ['nullable', 'array'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ops.max' => 'Un envoi porte au plus '.self::MAX_OPS.' opérations.',
            'ops.*.op_id.uuid' => "Chaque opération porte un identifiant unique : c'est lui qui empêche un rejeu de dupliquer l'écriture.",
        ];
    }

    /** @return list<array<string, mixed>> */
    public function ops(): array
    {
        /** @var list<array<string, mixed>> $ops */
        $ops = $this->input('ops');

        return $ops;
    }
}
