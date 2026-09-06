<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Trace d'idempotence d'une opération poussée.
 *
 * Une coupure réseau après l'écriture mais avant la réponse est le cas
 * ordinaire en mobilité : l'appareil rejoue son lot, et c'est ce registre qui
 * lui rend la réponse d'origine au lieu de dupliquer l'encaissement.
 *
 * @property string $operation_id
 * @property string $status
 * @property array<string, mixed>|null $result
 */
final class SyncOperation extends Model
{
    public const STATUS_APPLIED = 'applied';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    protected $fillable = [
        'device_id',
        'company_id',
        'operation_id',
        'entity',
        'op',
        'status',
        'result',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'result' => 'array',
        ];
    }
}
