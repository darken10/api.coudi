<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use App\Models\Contracts\Replicable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Association type de vêtement ↔ champ de mesure.
 *
 * Le pivot porte son propre identifiant, sans quoi un appareil ne pourrait
 * ni désigner ni retirer une association donnée.
 */
final class GarmentTypeField extends Model implements Replicable
{
    use BelongsToCompany;
    use Syncable;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'garment_type_id',
        'field_id',
        'sort_order',
    ];

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
            'sort_order' => 'integer',
        ];
    }
}
