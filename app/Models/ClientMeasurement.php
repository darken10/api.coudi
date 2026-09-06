<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mesure relevée sur un client pour un type de vêtement.
 *
 * Le triplet (client, type, champ) est unique : c'est cette clé naturelle —
 * et non l'UUID — qui sert de pivot au rapprochement lors d'un push.
 */
final class ClientMeasurement extends Model
{
    use BelongsToCompany;
    use Syncable;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'client_id',
        'garment_type_id',
        'field_id',
        'value',
        'measured_at',
    ];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<GarmentType, $this> */
    public function garmentType(): BelongsTo
    {
        return $this->belongsTo(GarmentType::class);
    }

    /** @return BelongsTo<MeasurementField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(MeasurementField::class, 'field_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
        ];
    }
}
